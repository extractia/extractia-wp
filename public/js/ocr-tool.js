/**
 * ExtractIA Public OCR Tool Widget — ocr-tool.js
 *
 * Handles .extractia-ocr-tool elements placed via [extractia_tool] shortcode
 * or the extractia/ocr-tool Gutenberg block.
 *
 * Workflow:
 *   1. Drag-drop or click-to-pick an image
 *   2. Optionally fill parameter inputs
 *   3. Click Analyze
 *   4. Display answer + explanation
 *
 * For YES_NO tools, the answer is shown as a green/red badge.
 */
(function () {
  "use strict";

  var cfg = window.ExtractIAConfig || {};
  var restUrl = (cfg.restUrl || "").replace(/\/$/, "");
  var nonce = cfg.nonce || "";
  var i18n = cfg.i18n || {};
  var maxFileMb = cfg.maxFileMb || 5;
  var MAX_BYTES = maxFileMb * 1024 * 1024;
  var ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp"];

  // ── Helpers ──────────────────────────────────────────────────────────────────

  function apiPost(endpoint, body) {
    return fetch(restUrl + endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
      body: JSON.stringify(body),
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) return Promise.reject(data);
        return data;
      });
    });
  }

  function fileToBase64(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function (e) {
        resolve(e.target.result);
      };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  function esc(s) {
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(String(s)));
    return div.innerHTML;
  }

  function i18nKey(err) {
    var key = err && err.i18nKey ? err.i18nKey : "genericError";
    return i18n[key] || i18n.genericError || "Error";
  }

  // ── Widget initialiser ───────────────────────────────────────────────────────

  function initOcrWidget(widget) {
    var toolId = widget.dataset.toolId || "";
    var outputType = (widget.dataset.outputType || "").toUpperCase();

    var dropzone = widget.querySelector(".extractia-ocr-dropzone");
    var fileInput = widget.querySelector(".extractia-ocr-file-input");
    var preview = widget.querySelector(".extractia-ocr-preview");
    var runBtn = widget.querySelector(".extractia-ocr-run-btn");
    var resultDiv = widget.querySelector(".extractia-ocr-result");
    var answerEl = widget.querySelector(".extractia-ocr-result__answer");
    var explanEl = widget.querySelector(".extractia-ocr-result__explanation");
    var errorDiv = widget.querySelector(".extractia-ocr-error");

    var currentB64 = null;

    // ── Local helpers ──────────────────────────────────────────────────────────

    function setError(msg) {
      if (!errorDiv) return;
      errorDiv.textContent = msg;
      errorDiv.style.display = msg ? "block" : "none";
    }

    function clearError() {
      setError("");
    }

    function collectParams() {
      var params = {};
      widget
        .querySelectorAll(".extractia-ocr-param__input")
        .forEach(function (el) {
          var key = el.dataset.paramKey;
          if (key) params[key] = el.value;
        });
      return params;
    }

    function setPreview(b64) {
      currentB64 = b64;
      if (preview) {
        preview.src = b64;
        preview.style.display = "block";
      }
      if (dropzone) dropzone.classList.add("has-image");
      if (runBtn) runBtn.disabled = false;
      clearError();
    }

    function handleFile(file) {
      if (!file) return;
      if (!ALLOWED_TYPES.includes(file.type)) {
        setError(
          i18n.unsupportedType ||
            "Unsupported file type (JPEG, PNG or WebP only).",
        );
        return;
      }
      if (file.size > MAX_BYTES) {
        setError(i18n.fileTooLarge || "File exceeds maximum size.");
        return;
      }
      fileToBase64(file)
        .then(setPreview)
        .catch(function () {
          setError(i18n.genericError || "Error reading file.");
        });
    }

    // ── Dropzone ───────────────────────────────────────────────────────────────

    if (dropzone) {
      dropzone.addEventListener("click", function () {
        if (fileInput) fileInput.click();
      });
      dropzone.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          if (fileInput) fileInput.click();
        }
      });
      dropzone.addEventListener("dragover", function (e) {
        e.preventDefault();
        dropzone.classList.add("dragover");
      });
      dropzone.addEventListener("dragleave", function () {
        dropzone.classList.remove("dragover");
      });
      dropzone.addEventListener("drop", function (e) {
        e.preventDefault();
        dropzone.classList.remove("dragover");
        if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
      });
    }

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        if (fileInput.files[0]) handleFile(fileInput.files[0]);
        fileInput.value = "";
      });
    }

    // ── Run button ─────────────────────────────────────────────────────────────

    if (runBtn) {
      runBtn.addEventListener("click", function () {
        if (!currentB64) {
          setError(i18n.noImage || "Please drop or select an image.");
          return;
        }
        if (!toolId) {
          setError("Tool ID not configured.");
          return;
        }

        clearError();
        runBtn.disabled = true;
        runBtn.textContent = "⌛ " + (i18n.processing || "Processing…");
        if (resultDiv) resultDiv.style.display = "none";

        var params = collectParams();

        apiPost("/ocr-run", {
          toolId: toolId,
          image: currentB64,
          params: params,
        })
          .then(function (data) {
            renderResult(data);
            runBtn.disabled = false;
            runBtn.textContent = i18n.analyze || "Analyze";
          })
          .catch(function (err) {
            setError(i18nKey(err));
            runBtn.disabled = false;
            runBtn.textContent = i18n.analyze || "Analyze";
          });
      });
    }

    // ── Render result ──────────────────────────────────────────────────────────

    function renderResult(data) {
      var answer = data.answer || "";
      var explanation = data.explanation || "";

      if (answerEl) {
        answerEl.className = "extractia-ocr-result__answer";
        if (outputType === "YES_NO") {
          var yes = /^s[ií]|^yes|^true/i.test(answer);
          answerEl.classList.add(yes ? "answer--yes" : "answer--no");
          answerEl.textContent = answer;
        } else {
          answerEl.textContent = answer;
        }
      }

      if (explanEl) {
        explanEl.textContent = explanation;
        explanEl.style.display = explanation ? "block" : "none";
      }

      if (resultDiv) resultDiv.style.display = "block";
    }

    // ── Initial state ──────────────────────────────────────────────────────────
    if (runBtn) runBtn.disabled = true;
  }

  // ── Boot ─────────────────────────────────────────────────────────────────────

  function boot() {
    document.querySelectorAll(".extractia-ocr-widget").forEach(initOcrWidget);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  window.ExtractIA = window.ExtractIA || {};
  window.ExtractIA.initOcrWidget = initOcrWidget;
  window.ExtractIA.initOcrAll = boot;
})();
