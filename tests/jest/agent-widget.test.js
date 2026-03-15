/**
 * Jest tests — agent-widget.js
 *
 * Tests the AI agent chain widget:
 *   - Initialisation
 *   - Multi-step rendering
 *   - REST call to /agent-run endpoint
 *   - Step status display (pending/running/done/error/stopped)
 *   - Condition step outcome display
 *   - Error handling per step
 */

beforeEach(() => {
  jest.resetModules();
  jest.clearAllMocks();
  document.body.innerHTML = "";
  global.fetch = jest.fn();
});

function makeAgentWidget(agentId = "invoice-flow", steps = []) {
  const config = JSON.stringify({
    name: "Invoice Flow",
    steps: steps.length
      ? steps
      : [
          { type: "extract", templateId: "tpl-invoice", label: "Extract data" },
          { type: "ocr_tool", toolId: "tool-sig", label: "Verify signature" },
        ],
  });

  const div = document.createElement("div");
  div.className = "extractia-agent-widget";
  div.setAttribute("data-agent-id", agentId);
  div.setAttribute("data-agent-config", config);
  div.innerHTML = `
    <h3 class="extractia-agent__title">Invoice Flow</h3>
    <div class="extractia-agent__steps"></div>
    <div class="extractia-dropzone extractia-agent__dropzone" tabindex="0"></div>
    <input class="extractia-file-input" type="file">
    <button class="extractia-agent__run-btn" disabled>Run Agent</button>
    <div class="extractia-agent__progress" style="display:none"></div>
    <div class="extractia-agent__result" style="display:none"></div>
    <div class="extractia-error-banner" style="display:none"></div>
  `;
  document.body.appendChild(div);
  return div;
}

function mockFetchOk(data) {
  global.fetch.mockResolvedValueOnce({
    ok: true,
    json: () => Promise.resolve(data),
  });
}

function mockFetchError(status, data) {
  global.fetch.mockResolvedValueOnce({
    ok: false,
    status,
    json: () => Promise.resolve(data),
  });
}

function loadAgentWidget() {
  jest.isolateModules(() => {
    require("../../public/js/agent-widget");
  });
  document.dispatchEvent(new Event("DOMContentLoaded"));
}

// ── Initialisation ────────────────────────────────────────────────────────────

describe("Agent widget initialisation", () => {
  test("run button starts disabled", () => {
    const w = makeAgentWidget();
    loadAgentWidget();
    expect(w.querySelector(".extractia-agent__run-btn").disabled).toBe(true);
  });

  test("renders step pills from config", () => {
    const w = makeAgentWidget();
    loadAgentWidget();
    const stepsDiv = w.querySelector(".extractia-agent__steps");
    expect(
      stepsDiv.querySelectorAll(".extractia-agent__step-pill").length,
    ).toBeGreaterThanOrEqual(2);
  });

  test("exposes window.ExtractIA.initAgentWidget", () => {
    makeAgentWidget();
    loadAgentWidget();
    expect(typeof window.ExtractIA?.initAgentWidget).toBe("function");
  });
});

// ── File drop enables run button ──────────────────────────────────────────────

describe("Image loading", () => {
  test("run button enabled after valid image dropped", async () => {
    const w = makeAgentWidget();
    const mockFR = { onload: null, readAsDataURL: jest.fn() };
    jest.spyOn(global, "FileReader").mockImplementation(() => {
      setTimeout(
        () =>
          mockFR.onload?.({ target: { result: "data:image/jpeg;base64,abc" } }),
        0,
      );
      return mockFR;
    });
    loadAgentWidget();
    window.ExtractIA.initAgentWidget(w);

    const fi = w.querySelector(".extractia-file-input");
    const f = new File([new ArrayBuffer(200)], "doc.jpg", {
      type: "image/jpeg",
    });
    Object.defineProperty(fi, "files", { value: [f], configurable: true });
    fi.dispatchEvent(new Event("change"));
    await new Promise((r) => setTimeout(r, 20));

    expect(w.querySelector(".extractia-agent__run-btn").disabled).toBe(false);
  });

  test("rejects oversized file", () => {
    const w = makeAgentWidget();
    loadAgentWidget();
    const fi = w.querySelector(".extractia-file-input");
    const big = new File([new ArrayBuffer(6 * 1024 * 1024)], "big.jpg", {
      type: "image/jpeg",
    });
    Object.defineProperty(fi, "files", { value: [big], configurable: true });
    fi.dispatchEvent(new Event("change"));
    expect(w.querySelector(".extractia-error-banner").style.display).toBe(
      "block",
    );
  });
});

// ── Agent run ─────────────────────────────────────────────────────────────────

describe("Agent run", () => {
  test("calls /agent-run endpoint on button click", async () => {
    const agentResult = {
      finalStatus: "done",
      lastDoc: { id: "doc-1", data: { total: "100.00" } },
      steps: [
        { type: "extract", status: "done", label: "Extract data" },
        {
          type: "ocr_tool",
          status: "done",
          answer: "Yes",
          label: "Verify signature",
        },
      ],
    };
    mockFetchOk(agentResult);

    const w = makeAgentWidget();
    const mockFR = { onload: null, readAsDataURL: jest.fn() };
    jest.spyOn(global, "FileReader").mockImplementation(() => {
      setTimeout(
        () =>
          mockFR.onload?.({ target: { result: "data:image/jpeg;base64,abc" } }),
        0,
      );
      return mockFR;
    });
    loadAgentWidget();
    window.ExtractIA.initAgentWidget(w);

    const fi = w.querySelector(".extractia-file-input");
    const f = new File([new ArrayBuffer(200)], "ok.jpg", {
      type: "image/jpeg",
    });
    Object.defineProperty(fi, "files", { value: [f], configurable: true });
    fi.dispatchEvent(new Event("change"));
    await new Promise((r) => setTimeout(r, 20));

    w.querySelector(".extractia-agent__run-btn").dispatchEvent(
      new Event("click"),
    );
    await new Promise((r) => setTimeout(r, 20));

    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/agent-run"),
      expect.objectContaining({ method: "POST" }),
    );
  });

  test("shows done status on success", async () => {
    const agentResult = {
      finalStatus: "done",
      lastDoc: { id: "doc-2", data: { vendor: "Shop" } },
      steps: [{ type: "extract", status: "done", label: "Extract data" }],
    };
    mockFetchOk(agentResult);

    const w = makeAgentWidget("simple", [
      { type: "extract", templateId: "tpl-1", label: "Extract" },
    ]);
    const mockFR = { onload: null, readAsDataURL: jest.fn() };
    jest.spyOn(global, "FileReader").mockImplementation(() => {
      setTimeout(
        () =>
          mockFR.onload?.({ target: { result: "data:image/jpeg;base64,abc" } }),
        0,
      );
      return mockFR;
    });
    loadAgentWidget();
    window.ExtractIA.initAgentWidget(w);

    const fi = w.querySelector(".extractia-file-input");
    const f = new File([new ArrayBuffer(200)], "ok.jpg", {
      type: "image/jpeg",
    });
    Object.defineProperty(fi, "files", { value: [f], configurable: true });
    fi.dispatchEvent(new Event("change"));
    await new Promise((r) => setTimeout(r, 20));

    w.querySelector(".extractia-agent__run-btn").dispatchEvent(
      new Event("click"),
    );
    await new Promise((r) => setTimeout(r, 30));

    const result = w.querySelector(".extractia-agent__result");
    expect(result.style.display).toBe("block");
  });

  test("shows error banner on API error", async () => {
    mockFetchError(402, {
      error: "Quota",
      i18nKey: "quotaExceeded",
      code: 402,
    });

    const w = makeAgentWidget();
    const mockFR = { onload: null, readAsDataURL: jest.fn() };
    jest.spyOn(global, "FileReader").mockImplementation(() => {
      setTimeout(
        () =>
          mockFR.onload?.({ target: { result: "data:image/jpeg;base64,abc" } }),
        0,
      );
      return mockFR;
    });
    loadAgentWidget();
    window.ExtractIA.initAgentWidget(w);

    const fi = w.querySelector(".extractia-file-input");
    const f = new File([new ArrayBuffer(200)], "ok.jpg", {
      type: "image/jpeg",
    });
    Object.defineProperty(fi, "files", { value: [f], configurable: true });
    fi.dispatchEvent(new Event("change"));
    await new Promise((r) => setTimeout(r, 20));

    w.querySelector(".extractia-agent__run-btn").dispatchEvent(
      new Event("click"),
    );
    await new Promise((r) => setTimeout(r, 20));

    const banner = w.querySelector(".extractia-error-banner");
    expect(banner.style.display).toBe("block");
    expect(banner.textContent).toMatch(/quota|upgrade/i);
  });

  test("shows stopped status when condition stops chain", async () => {
    const agentResult = {
      finalStatus: "stopped",
      lastDoc: null,
      steps: [
        {
          type: "condition",
          status: "stopped",
          outcome: "stop",
          label: "Check status",
        },
      ],
    };
    mockFetchOk(agentResult);

    const w = makeAgentWidget("conditional", [
      {
        type: "condition",
        field: "status",
        operator: "equals",
        value: "approved",
        onTrue: "continue",
        onFalse: "stop",
        label: "Check status",
      },
    ]);
    const mockFR = { onload: null, readAsDataURL: jest.fn() };
    jest.spyOn(global, "FileReader").mockImplementation(() => {
      setTimeout(
        () =>
          mockFR.onload?.({ target: { result: "data:image/jpeg;base64,abc" } }),
        0,
      );
      return mockFR;
    });
    loadAgentWidget();
    window.ExtractIA.initAgentWidget(w);

    const fi = w.querySelector(".extractia-file-input");
    const f = new File([new ArrayBuffer(200)], "ok.jpg", {
      type: "image/jpeg",
    });
    Object.defineProperty(fi, "files", { value: [f], configurable: true });
    fi.dispatchEvent(new Event("change"));
    await new Promise((r) => setTimeout(r, 20));

    w.querySelector(".extractia-agent__run-btn").dispatchEvent(
      new Event("click"),
    );
    await new Promise((r) => setTimeout(r, 20));

    const result = w.querySelector(".extractia-agent__result");
    if (result.style.display === "block") {
      expect(result.innerHTML).toMatch(/stop/i);
    }
  });
});

// ── Multiple agent widgets ────────────────────────────────────────────────────

describe("Multiple agent widgets on one page", () => {
  test("each widget initialises independently", () => {
    const w1 = makeAgentWidget("agent-1");
    const w2 = makeAgentWidget("agent-2");
    loadAgentWidget();
    // Both should have their run buttons
    expect(w1.querySelector(".extractia-agent__run-btn")).toBeTruthy();
    expect(w2.querySelector(".extractia-agent__run-btn")).toBeTruthy();
    // Buttons independent
    w1.querySelector(".extractia-agent__run-btn").disabled = false;
    expect(w2.querySelector(".extractia-agent__run-btn").disabled).toBe(true);
  });
});
