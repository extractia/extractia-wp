/**
 * ExtractIA Upload Widget — upload-widget.js
 *
 * Implements the full document extraction workflow:
 *   STEP 1 — Template selection  (via dropdown or pre-set)
 *   STEP 2 — Image capture       (drag-drop, file picker, camera)
 *   STEP 3 — Processing          (POST to WP REST proxy)
 *   STEP 4 — Results display     (field table, AI summary, copy/export)
 *
 * All API calls go through /wp-json/extractia/v1/ with the WP nonce.
 * Camera uses MediaDevices.getUserMedia(); falls back gracefully.
 * Multipage: user can add multiple images before processing.
 *
 * Config & i18n injected by PHP via wp_localize_script as ExtractIAConfig.
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

  function apiGet(endpoint) {
    return fetch(restUrl + endpoint, {
      headers: { "X-WP-Nonce": nonce },
    }).then(function (r) {
      return r.json();
    });
  }

  // ── Helper: file → base64 ───────────────────────────────────────────────────

  function fileToBase64(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function (e) {
        resolve(e.target.result);
      };
      reader.onerror = reject;
      if (file.type === "application/pdf") {
        // For PDFs: send as base64 directly; the API handles PDF→image server-side
        reader.readAsDataURL(file);
      } else {
        reader.readAsDataURL(file);
      }
    });
  }

  // ── Helper: objects to CSV ──────────────────────────────────────────────────

  function objToCsv(data) {
    var keys = Object.keys(data);
    var row = keys.map(function (k) {
      return JSON.stringify(String(data[k] ?? ""));
    });
    return keys.join(",") + "\n" + row.join(",");
  }

  // ── Helper: download blob ───────────────────────────────────────────────────

  function downloadBlob(content, filename, mime) {
    var blob = new Blob([content], { type: mime });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.style.display = "none";
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }, 1000);
  }

  // ── Helper: sanitize text for DOM ─────────────────────────────────────────

  function esc(s) {
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(String(s)));
    return div.innerHTML;
  }

  // ── Widget initialiser ──────────────────────────────────────────────────────

  function initWidget(widget) {
    // Config from data-* attributes
    var presetTemplate = widget.dataset.template || "";
    var hideSelector = widget.dataset.hideSelector === "true";
    var allowMultipage = widget.dataset.multipage !== "false";
    var showSummary = widget.dataset.showSummary !== "false";
    var presetBtn = widget.dataset.buttonText || "";

    // DOM refs
    var tplSelect = widget.querySelector(".extractia-template-select");
    var dropzone = widget.querySelector(".extractia-dropzone");
    var fileInput = widget.querySelector(".extractia-file-input");
    var cameraBtn = widget.querySelector(".extractia-camera-btn");
    var cameraVideo = widget.querySelector(".extractia-camera-preview");
    var snapBtn = widget.querySelector(".extractia-snap-btn");
    var cameraCanvas = widget.querySelector(".extractia-camera-canvas");
    var pagesStrip = widget.querySelector(".extractia-pages-strip");
    var addPageBtn = widget.querySelector(".extractia-add-page-btn");
    var processBtn = widget.querySelector(".extractia-process-btn");
    var progressStep = widget.querySelector(".extractia-step--progress");
    var resultsStep = widget.querySelector(".extractia-step--results");
    var summaryBox = widget.querySelector(".extractia-summary-box");
    var summaryBtn = widget.querySelector(".extractia-summary-btn");
    var fieldsTable = widget.querySelector(".extractia-fields-table");
    var copyBtn = widget.querySelector(".extractia-copy-btn");
    var csvBtn = widget.querySelector(".extractia-csv-btn");
    var resetBtn = widget.querySelector(".extractia-reset-btn");
    var errorBanner = widget.querySelector(".extractia-error-banner");
    var usageBarWrap = widget.querySelector(".extractia-usage-bar-wrap");
    var usageBarFill = widget.querySelector(".extractia-usage-bar__fill");
    var usageBarLbl = widget.querySelector(".extractia-usage-bar__label");

    // State
    var images = []; // Array of base64 strings
    var lastDoc = null; // Last processed document object
    var cameraStream = null;
    var cameraActive = false;

    // ── Helpers ───────────────────────────────────────────────────────────────

    function selectedTemplate() {
      if (hideSelector || !tplSelect) return presetTemplate;
      return tplSelect.value || presetTemplate;
    }

    function setError(msg) {
      if (!errorBanner) return;
      errorBanner.textContent = msg;
      errorBanner.style.display = msg ? "block" : "none";
    }

    function clearError() {
      setError("");
    }

    function showStep(step) {
      if (progressStep)
        progressStep.style.display = step === "progress" ? "block" : "none";
      if (resultsStep)
        resultsStep.style.display = step === "results" ? "block" : "none";
    }

    function refreshProcessBtn() {
      if (processBtn)
        processBtn.disabled = images.length === 0 || !selectedTemplate();
    }

    function i18nKey(err) {
      var key = err && err.i18nKey ? err.i18nKey : "genericError";
      return i18n[key] || i18n.genericError || "Error";
    }

    // ── Image management ──────────────────────────────────────────────────────

    function addImage(b64) {
      if (!allowMultipage && images.length >= 1) {
        images = [b64];
      } else {
        images.push(b64);
      }
      renderStrip();
      if (addPageBtn)
        addPageBtn.style.display = allowMultipage ? "inline-flex" : "none";
      refreshProcessBtn();
    }

    function removeImage(idx) {
      images.splice(idx, 1);
      renderStrip();
      if (images.length === 0 && addPageBtn) addPageBtn.style.display = "none";
      refreshProcessBtn();
    }

    function renderStrip() {
      if (!pagesStrip) return;
      pagesStrip.innerHTML = "";
      images.forEach(function (b64, i) {
        var thumb = document.createElement("div");
        thumb.className = "extractia-page-thumb";
        var img = document.createElement("img");
        img.src = b64;
        img.alt = "";
        var rmBtn = document.createElement("button");
        rmBtn.type = "button";
        rmBtn.className = "extractia-page-thumb__remove";
        rmBtn.textContent = "×";
        rmBtn.setAttribute("aria-label", "Remove page " + (i + 1));
        rmBtn.addEventListener("click", function () {
          removeImage(i);
        });
        var num = document.createElement("span");
        num.className = "extractia-page-thumb__num";
        num.textContent = String(i + 1);
        thumb.appendChild(img);
        thumb.appendChild(rmBtn);
        thumb.appendChild(num);
        pagesStrip.appendChild(thumb);
      });
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
        .then(addImage)
        .catch(function () {
          setError(i18n.genericError || "Error reading file.");
        });
    }

    // ── Dropzone events ───────────────────────────────────────────────────────

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
        var files = e.dataTransfer.files;
        for (var i = 0; i < files.length; i++) {
          handleFile(files[i]);
        }
      });
    }

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        var files = fileInput.files;
        for (var i = 0; i < files.length; i++) handleFile(files[i]);
        fileInput.value = "";
      });
    }

    // ── Camera ────────────────────────────────────────────────────────────────

    if (cameraBtn) {
      var hasCamera = !!(
        navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      );
      if (!hasCamera) {
        cameraBtn.style.display = "none";
      } else {
        cameraBtn.addEventListener("click", function () {
          if (cameraActive) {
            stopCamera();
          } else {
            startCamera();
          }
        });
      }
    }

    function startCamera() {
      navigator.mediaDevices
        .getUserMedia({ video: { facingMode: "environment" } })
        .then(function (stream) {
          cameraStream = stream;
          cameraActive = true;
          if (cameraVideo) {
            cameraVideo.srcObject = stream;
            cameraVideo.style.display = "block";
          }
          if (snapBtn) snapBtn.style.display = "inline-flex";
          if (cameraBtn)
            cameraBtn.textContent = "✕ " + (i18n.close || "Close camera");
        })
        .catch(function (err) {
          setError("Camera error: " + err.message);
        });
    }

    function stopCamera() {
      if (cameraStream) {
        cameraStream.getTracks().forEach(function (t) {
          t.stop();
        });
        cameraStream = null;
      }
      cameraActive = false;
      if (cameraVideo) {
        cameraVideo.srcObject = null;
        cameraVideo.style.display = "none";
      }
      if (snapBtn) snapBtn.style.display = "none";
      if (cameraBtn)
        cameraBtn.textContent = "📷 " + (i18n.useCamera || "Use camera");
    }

    if (snapBtn) {
      snapBtn.addEventListener("click", function () {
        if (!cameraVideo || !cameraCanvas) return;
        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        cameraCanvas.getContext("2d").drawImage(cameraVideo, 0, 0);
        var b64 = cameraCanvas.toDataURL("image/jpeg", 0.92);
        addImage(b64);
        if (!allowMultipage) stopCamera();
      });
    }

    // ── Add-page button ───────────────────────────────────────────────────────

    if (addPageBtn) {
      addPageBtn.addEventListener("click", function () {
        if (fileInput) fileInput.click();
      });
    }

    // ── Template selector ─────────────────────────────────────────────────────

    if (tplSelect) {
      tplSelect.addEventListener("change", refreshProcessBtn);
    }

    // ── Fetch usage bar (non-blocking) ────────────────────────────────────────

    if (usageBarWrap) {
      apiGet("/usage")
        .then(function (data) {
          var used = data.documentsUsed || 0;
          var limit = data.documentsLimit || 0;
          if (limit <= 0) return;
          var pct = Math.min(100, Math.round((used / limit) * 100));
          var color = pct >= 90 ? "#dc2626" : pct >= 70 ? "#d97706" : "#059669";
          if (usageBarFill) {
            usageBarFill.style.width = pct + "%";
            usageBarFill.style.background = color;
          }
          if (usageBarLbl) {
            usageBarLbl.textContent = used + " / " + limit + " docs";
          }
          usageBarWrap.style.display = "flex";
        })
        .catch(function () {}); // non-critical
    }

    // ── Process ───────────────────────────────────────────────────────────────

    if (processBtn) {
      processBtn.addEventListener("click", function () {
        var tplId = selectedTemplate();
        if (!tplId) {
          setError(i18n.noTemplate || "");
          return;
        }
        if (images.length === 0) {
          setError(i18n.noImage || "");
          return;
        }

        clearError();
        processBtn.disabled = true;
        showStep("progress");

        var promise;
        if (images.length === 1) {
          promise = apiPost("/process", {
            templateId: tplId,
            image: images[0],
          });
        } else {
          promise = apiPost("/process-multipage", {
            templateId: tplId,
            images: images,
          });
        }

        promise
          .then(function (doc) {
            lastDoc = doc;
            showStep("results");
            renderResults(doc);
          })
          .catch(function (err) {
            showStep("");
            processBtn.disabled = false;
            setError(i18nKey(err));
          });
      });
    }

    // ── Render results ────────────────────────────────────────────────────────

    function renderResults(doc) {
      if (!fieldsTable) return;

      var data = doc.data || {};
      var html = '<table class="extractia-fields-table"><tbody>';

      Object.keys(data).forEach(function (key) {
        var val = data[key];
        if (val === null || val === undefined) val = "";
        if (Array.isArray(val)) val = val.join(", ");
        if (typeof val === "object") val = JSON.stringify(val);
        html +=
          "<tr><th>" + esc(key) + "</th><td>" + esc(String(val)) + "</td></tr>";
      });

      html += "</tbody></table>";
      fieldsTable.innerHTML = html;

      if (summaryBox) summaryBox.style.display = "none";
      if (summaryBtn)
        summaryBtn.style.display =
          showSummary && doc.id ? "inline-flex" : "none";
    }

    // ── AI Summary ────────────────────────────────────────────────────────────

    if (summaryBtn) {
      summaryBtn.addEventListener("click", function () {
        if (!lastDoc || !lastDoc.id) return;
        summaryBtn.disabled = true;
        summaryBtn.textContent = "✨ " + (i18n.processing || "Processing…");

        apiPost("/summary", { docId: lastDoc.id })
          .then(function (data) {
            if (summaryBox) {
              summaryBox.textContent = data.summary || "";
              summaryBox.style.display = "block";
            }
            summaryBtn.style.display = "none";
          })
          .catch(function (err) {
            setError(i18nKey(err));
            summaryBtn.disabled = false;
            summaryBtn.textContent =
              "✨ " + (i18n.aiSummary || "Generate AI summary");
          });
      });
    }

    // ── Copy JSON ─────────────────────────────────────────────────────────────

    if (copyBtn) {
      copyBtn.addEventListener("click", function () {
        if (!lastDoc) return;
        var txt = JSON.stringify(lastDoc.data || lastDoc, null, 2);
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard
            .writeText(txt)
            .then(function () {
              var orig = copyBtn.textContent;
              copyBtn.textContent = "✓";
              setTimeout(function () {
                copyBtn.textContent = orig;
              }, 1500);
            })
            .catch(function () {});
        }
      });
    }

    // ── Download CSV ──────────────────────────────────────────────────────────

    if (csvBtn) {
      csvBtn.addEventListener("click", function () {
        if (!lastDoc) return;
        var csv = objToCsv(lastDoc.data || {});
        downloadBlob(
          "\uFEFF" + csv,
          "extractia-result.csv",
          "text/csv;charset=utf-8;",
        );
      });
    }

    // ── Reset ─────────────────────────────────────────────────────────────────

    if (resetBtn) {
      resetBtn.addEventListener("click", reset);
    }

    function reset() {
      images = [];
      lastDoc = null;
      stopCamera();
      renderStrip();
      showStep("");
      clearError();
      if (processBtn) processBtn.disabled = true;
      if (addPageBtn) addPageBtn.style.display = "none";
      if (summaryBox) summaryBox.style.display = "none";
      if (fieldsTable) fieldsTable.innerHTML = "";
    }

    // ── Initial state ─────────────────────────────────────────────────────────

    if (hideSelector && tplSelect) {
      var stepTpl = widget.querySelector(".extractia-step--template");
      if (stepTpl) stepTpl.style.display = "none";
    }

    if (presetTemplate && tplSelect) {
      tplSelect.value = presetTemplate;
    }

    if (presetBtn && processBtn) {
      processBtn.textContent = presetBtn;
    }

    refreshProcessBtn();
  }

  // ── Boot ────────────────────────────────────────────────────────────────────

  function boot() {
    document.querySelectorAll(".extractia-widget").forEach(initWidget);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // Expose for programmatic use (e.g. dynamic content via AJAX)
  window.ExtractIA = window.ExtractIA || {};
  window.ExtractIA.initWidget = initWidget;
  window.ExtractIA.initAll = boot;
})();
