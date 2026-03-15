/**
 * ExtractIA Admin JS
 * Handles OCR Tools admin panel: image drag-drop and tool runner.
 * Also provides the Gutenberg block editor sidebar (blocks-editor.js is separate).
 */
(function () {
  "use strict";

  var cfg = window.ExtractIAAdmin || {};
  var ajaxUrl = cfg.ajaxUrl || "";
  var nonce = cfg.nonce || "";

  // ── OCR tool cards ──────────────────────────────────────────────────────────

  document.querySelectorAll(".extractia-ocr-card").forEach(function (card) {
    var toolId = card.dataset.toolId;
    var dropzone = card.querySelector(".extractia-admin-drop");
    var fileInput = card.querySelector(".extractia-file-input");
    var preview = card.querySelector(".extractia-admin-preview-wrap");
    var runBtn = card.querySelector(".extractia-admin-ocr-run");
    var spinner = card.querySelector(".extractia-admin-spinner");
    var resultEl = card.querySelector(".extractia-ocr-admin-result");
    var answerEl = card.querySelector(".extractia-ocr-admin-result__answer");
    var explEl = card.querySelector(".extractia-ocr-admin-result__explanation");
    var errorEl = card.querySelector(".extractia-admin-error");
    var imageB64 = null;

    // ── File select ──
    function handleFile(file) {
      if (!file || !file.type.startsWith("image/")) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        imageB64 = e.target.result;
        preview.innerHTML = '<img src="' + escAttr(imageB64) + '" alt="" />';
        runBtn.disabled = false;
        resultEl.style.display = "none";
        errorEl.style.display = "none";
      };
      reader.readAsDataURL(file);
    }

    if (dropzone) {
      dropzone.addEventListener("click", function () {
        fileInput && fileInput.click();
      });
      dropzone.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          fileInput && fileInput.click();
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
        handleFile(e.dataTransfer.files[0]);
      });
    }

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        handleFile(fileInput.files[0]);
      });
    }

    // ── Run ──
    if (runBtn) {
      runBtn.addEventListener("click", function () {
        if (!imageB64) return;

        var params = {};
        card
          .querySelectorAll(".extractia-ocr-param__input")
          .forEach(function (inp) {
            params[inp.dataset.paramKey] = inp.value.trim();
          });

        runBtn.disabled = true;
        spinner.style.display = "block";
        errorEl.style.display = "none";
        resultEl.style.display = "none";

        var body =
          "action=extractia_run_ocr_admin" +
          "&nonce=" +
          encodeURIComponent(nonce) +
          "&tool_id=" +
          encodeURIComponent(toolId) +
          "&image=" +
          encodeURIComponent(imageB64);

        Object.keys(params).forEach(function (k) {
          body +=
            "&params[" +
            encodeURIComponent(k) +
            "]=" +
            encodeURIComponent(params[k]);
        });

        fetch(ajaxUrl, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: body,
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (res) {
            spinner.style.display = "none";
            runBtn.disabled = false;
            if (res.success) {
              answerEl.textContent = res.data.answer || "";
              explEl.textContent = res.data.explanation || "";
              resultEl.style.display = "block";
            } else {
              var err =
                res.data && res.data.error ? res.data.error : "Unknown error";
              errorEl.textContent = err;
              errorEl.style.display = "block";
            }
          })
          .catch(function (err) {
            spinner.style.display = "none";
            runBtn.disabled = false;
            errorEl.textContent = String(err);
            errorEl.style.display = "block";
          });
      });
    }
  });

  // ── Utility ─────────────────────────────────────────────────────────────────

  function escAttr(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }
})();
