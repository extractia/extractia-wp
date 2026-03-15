/**
 * ExtractIA Agent Widget — agent-widget.js
 *
 * Powers the [extractia_agent] shortcode and the extractia/agent Gutenberg block.
 *
 * Workflow:
 *   1. User drops or picks an image
 *   2. Click "Run Agent"
 *   3. POST to /wp-json/extractia/v1/agent-run
 *   4. Render per-step status pills (pending → running → done/error/stopped)
 *   5. Show final result: last document fields, OCR answers, AI summary
 *
 * Multiple independent agent widgets can coexist on one page.
 * Each widget's state is isolated via closure.
 */
(function () {
  "use strict";

  var cfg = window.ExtractIAConfig || {};
  var restUrl = (cfg.restUrl || "").replace(/\/$/, "");
  var nonce = cfg.nonce || "";
  var i18n = cfg.i18n || {};
  var maxFileMb = cfg.maxFileMb || 5;
  var MAX_BYTES = maxFileMb * 1024 * 1024;
  var ALLOWED_TYPES = [
    "image/jpeg",
    "image/png",
    "image/webp",
    "application/pdf",
  ];

  // ── Helper: REST call ───────────────────────────────────────────────────────

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

  // ── Widget initialiser ──────────────────────────────────────────────────────

  function initAgentWidget(widget) {
    var agentId = widget.dataset.agentId || "";
    var configRaw = widget.dataset.agentConfig || "{}";
    var agentConfig = {};
    try {
      agentConfig = JSON.parse(configRaw);
    } catch (e) {}

    var steps = agentConfig.steps || [];

    // DOM refs
    var stepsDiv = widget.querySelector(".extractia-agent__steps");
    var dropzone = widget.querySelector(
      ".extractia-agent__dropzone, .extractia-dropzone",
    );
    var fileInput = widget.querySelector(".extractia-file-input");
    var runBtn = widget.querySelector(".extractia-agent__run-btn");
    var progressDiv = widget.querySelector(".extractia-agent__progress");
    var resultDiv = widget.querySelector(".extractia-agent__result");
    var errorBanner = widget.querySelector(".extractia-error-banner");

    // State
    var currentB64 = null;

    // ── Helpers ───────────────────────────────────────────────────────────────

    function setError(msg) {
      if (!errorBanner) return;
      errorBanner.textContent = msg;
      errorBanner.style.display = msg ? "block" : "none";
    }

    function clearError() {
      setError("");
    }

    // ── Render step pills ─────────────────────────────────────────────────────

    function renderStepPills(liveSteps) {
      if (!stepsDiv) return;
      stepsDiv.innerHTML = "";

      var source = liveSteps || steps;
      source.forEach(function (step, i) {
        var pill = document.createElement("div");
        pill.className = "extractia-agent__step-pill";
        var status = step.status || "pending";
        var label = step.label || step.type || "Step " + (i + 1);
        pill.classList.add("step-" + status);

        var icon = {
          pending: "○",
          running: "⌛",
          done: "✓",
          error: "✗",
          stopped: "•",
          skipped: "—",
        };
        pill.innerHTML =
          '<span class="step-icon">' +
          (icon[status] || "○") +
          "</span>" +
          '<span class="step-label">' +
          esc(label) +
          "</span>" +
          (status === "error"
            ? '<span class="step-error">' + esc(step.error || "") + "</span>"
            : "") +
          (step.answer !== undefined
            ? '<span class="step-answer">' + esc(step.answer) + "</span>"
            : "") +
          (status === "stopped"
            ? '<span class="step-stopped">stopped</span>'
            : "");

        stepsDiv.appendChild(pill);
      });
    }

    // ── Render final result ───────────────────────────────────────────────────

    function renderResult(data) {
      if (!resultDiv) return;
      resultDiv.innerHTML = "";

      var finalStatus = data.finalStatus || "done";
      var header = document.createElement("div");
      header.className = "extractia-agent__result-header status-" + finalStatus;
      header.textContent =
        finalStatus === "done"
          ? i18n.done || "Done"
          : finalStatus === "stopped"
            ? i18n.agentStopped || "Agent stopped by condition"
            : i18n.genericError || "Error";
      resultDiv.appendChild(header);

      // Render last doc fields
      var lastDoc = data.lastDoc;
      if (lastDoc && lastDoc.data && Object.keys(lastDoc.data).length) {
        var table = document.createElement("table");
        table.className = "extractia-fields-table";
        var tbody = document.createElement("tbody");
        Object.keys(lastDoc.data).forEach(function (key) {
          var val = lastDoc.data[key];
          if (Array.isArray(val)) val = val.join(", ");
          if (typeof val === "object" && val !== null)
            val = JSON.stringify(val);
          var tr = document.createElement("tr");
          tr.innerHTML =
            "<th>" + esc(key) + "</th><td>" + esc(String(val ?? "")) + "</td>";
          tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        resultDiv.appendChild(table);
      }

      // Render OCR answers from steps
      var ocrSteps = (data.steps || []).filter(function (s) {
        return s.type === "ocr_tool" && s.answer;
      });
      if (ocrSteps.length) {
        var ocrDiv = document.createElement("div");
        ocrDiv.className = "extractia-agent__ocr-answers";
        ocrSteps.forEach(function (s) {
          var item = document.createElement("div");
          item.className = "extractia-agent__ocr-item";
          item.innerHTML =
            "<strong>" +
            esc(s.label || s.toolId || "OCR") +
            ":</strong> " +
            '<span class="answer">' +
            esc(s.answer) +
            "</span>" +
            (s.explanation ? " — <em>" + esc(s.explanation) + "</em>" : "");
          ocrDiv.appendChild(item);
        });
        resultDiv.appendChild(ocrDiv);
      }

      // Render AI summary if present
      var summaryStep = (data.steps || []).find(function (s) {
        return s.type === "summary" && s.summary;
      });
      if (summaryStep) {
        var sumBox = document.createElement("div");
        sumBox.className = "extractia-summary-box";
        sumBox.textContent = summaryStep.summary;
        resultDiv.appendChild(sumBox);
      }

      resultDiv.style.display = "block";
      if (progressDiv) progressDiv.style.display = "none";
    }

    // ── File handling ─────────────────────────────────────────────────────────

    function handleFile(file) {
      if (!file) return;
      if (!ALLOWED_TYPES.includes(file.type)) {
        setError(i18n.unsupportedType || "Unsupported file type.");
        return;
      }
      if (file.size > MAX_BYTES) {
        setError(i18n.fileTooLarge || "File too large.");
        return;
      }
      clearError();
      fileToBase64(file)
        .then(function (b64) {
          currentB64 = b64;
          if (runBtn) runBtn.disabled = false;
          // Show a subtle preview indicator
          if (dropzone) dropzone.classList.add("has-image");
        })
        .catch(function () {
          setError(i18n.genericError || "Error reading file.");
        });
    }

    // ── Dropzone ──────────────────────────────────────────────────────────────

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

    // ── Run button ────────────────────────────────────────────────────────────

    if (runBtn) {
      runBtn.addEventListener("click", function () {
        if (!currentB64) {
          setError(i18n.noImage || "Please add an image.");
          return;
        }
        if (!agentId && !agentConfig.steps) {
          setError(i18n.genericError || "Agent not configured.");
          return;
        }

        clearError();
        runBtn.disabled = true;
        runBtn.textContent = "⌛ " + (i18n.processing || "Running agent…");

        // Render all steps as "pending" while waiting
        renderStepPills(
          steps.map(function (s) {
            return Object.assign({}, s, { status: "pending" });
          }),
        );

        if (progressDiv) progressDiv.style.display = "block";
        if (resultDiv) resultDiv.style.display = "none";

        apiPost("/agent-run", {
          agentId: agentId,
          image: currentB64,
          agentConfig: agentConfig,
        })
          .then(function (data) {
            renderStepPills(data.steps || []);
            renderResult(data);
            runBtn.disabled = false;
            runBtn.textContent = i18n.runAgent || "Run Agent";
          })
          .catch(function (err) {
            if (progressDiv) progressDiv.style.display = "none";
            setError(i18nKey(err));
            runBtn.disabled = false;
            runBtn.textContent = i18n.runAgent || "Run Agent";
          });
      });
    }

    // ── Initial state ─────────────────────────────────────────────────────────
    renderStepPills();
    if (runBtn) runBtn.disabled = true;
  }

  // ── Boot ─────────────────────────────────────────────────────────────────────

  function boot() {
    document
      .querySelectorAll(".extractia-agent-widget")
      .forEach(initAgentWidget);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  window.ExtractIA = window.ExtractIA || {};
  window.ExtractIA.initAgentWidget = initAgentWidget;
  window.ExtractIA.initAgentAll = boot;
})();
