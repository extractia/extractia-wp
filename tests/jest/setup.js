/**
 * Jest global setup — defines ExtractIAConfig and DOM helpers
 * used by all public JS widget tests.
 */

// Global config injected by wp_localize_script in production
global.ExtractIAConfig = {
  restUrl: "https://example.com/wp-json/extractia/v1",
  nonce: "test-nonce-123",
  maxFileMb: 5,
  version: "1.0.0",
  i18n: {
    dropHere: "Drop your document here or click to browse",
    orUseCamera: "or use camera",
    selectTemplate: "Select a form template",
    processing: "Processing…",
    done: "Extraction complete",
    addPage: "+ Add page",
    process: "Process document",
    reset: "Start over",
    aiSummary: "Generate AI summary",
    copyJson: "Copy JSON",
    downloadCsv: "Download CSV",
    noTemplate: "Please select a template first.",
    noImage: "Please add at least one image.",
    fileTooLarge: "File exceeds the maximum allowed size.",
    unsupportedType: "Unsupported file type.",
    quotaExceeded: "Document quota reached. Upgrade your plan.",
    tierError: "Feature not on your current plan.",
    authError: "API key error.",
    rateLimited: "Too many requests. Please wait.",
    genericError: "Something went wrong.",
    runTool: "Analyze",
    toolResult: "Result",
    close: "Close camera",
    useCamera: "Use camera",
    analyze: "Analyze",
  },
};

// Minimal MediaDevices stub (camera tests)
global.navigator.mediaDevices = {
  getUserMedia: jest.fn().mockResolvedValue({
    getTracks: () => [{ stop: jest.fn() }],
  }),
};

// fetch stub — can be overridden per test via global.fetch = jest.fn(...)
global.fetch = jest.fn();

// navigator.clipboard
Object.defineProperty(global.navigator, "clipboard", {
  value: { writeText: jest.fn().mockResolvedValue(undefined) },
  writable: true,
});

// URL.createObjectURL / revokeObjectURL
global.URL.createObjectURL = jest.fn(() => "blob:mock-url");
global.URL.revokeObjectURL = jest.fn();
