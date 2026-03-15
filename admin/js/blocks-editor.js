/**
 * ExtractIA Blocks Editor — blocks-editor.js
 *
 * Registers Gutenberg blocks in the editor using the WP blocks API.
 * The actual rendering is server-side (render_callback in PHP).
 * This file only provides the editor UI / inspector controls.
 */
(function () {
  "use strict";

  var el = wp.element.createElement;
  var registerBlockType = wp.blocks.registerBlockType;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var PanelBody = wp.components.PanelBody;
  var TextControl = wp.components.TextControl;
  var ToggleControl = wp.components.ToggleControl;
  var RangeControl = wp.components.RangeControl;
  var SelectControl = wp.components.SelectControl;
  var Placeholder = wp.components.Placeholder;
  var Spinner = wp.components.Spinner;
  var data = window.ExtractIABlocksData || {};
  var restUrl = data.restUrl || "";
  var nonce = data.nonce || "";

  var fetchJson = function (endpoint) {
    return fetch(restUrl + endpoint, {
      headers: {
        "X-WP-Nonce": nonce,
        "Content-Type": "application/json",
      },
    }).then(function (r) {
      return r.json();
    });
  };

  // ─────────────────────────────────────────────────────────────────────────────
  // Block: extractia/upload
  // ─────────────────────────────────────────────────────────────────────────────
  registerBlockType("extractia/upload", {
    title: "ExtractIA Upload",
    description:
      "AI document extraction widget with drag-drop, camera, and workflow steps.",
    icon: "media-document",
    category: "widgets",
    attributes: {
      template: { type: "string", default: "" },
      hideSelector: { type: "boolean", default: false },
      multipage: { type: "boolean", default: true },
      showSummary: { type: "boolean", default: true },
      className: { type: "string", default: "" },
      title: { type: "string", default: "" },
      buttonText: { type: "string", default: "" },
    },
    edit: function (props) {
      var attrs = props.attributes;
      var setAttr = props.setAttributes;

      var [templates, setTemplates] = wp.element.useState([]);
      var [loading, setLoading] = wp.element.useState(true);

      wp.element.useEffect(function () {
        fetchJson("/templates")
          .then(function (res) {
            setTemplates(Array.isArray(res) ? res : []);
            setLoading(false);
          })
          .catch(function () {
            setLoading(false);
          });
      }, []);

      var tplOptions = [{ label: "— user selects —", value: "" }].concat(
        templates.map(function (t) {
          return { label: t.label, value: t.id };
        }),
      );

      return el(
        "div",
        { className: "extractia-block-editor-wrap" },
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: "Template & Workflow", initialOpen: true },
            loading
              ? el(Spinner)
              : el(SelectControl, {
                  label: "Default Template",
                  value: attrs.template,
                  options: tplOptions,
                  onChange: function (v) {
                    setAttr({ template: v });
                  },
                }),
            el(ToggleControl, {
              label: "Hide template selector",
              checked: attrs.hideSelector,
              onChange: function (v) {
                setAttr({ hideSelector: v });
              },
            }),
            el(ToggleControl, {
              label: "Allow multi-page documents",
              checked: attrs.multipage,
              onChange: function (v) {
                setAttr({ multipage: v });
              },
            }),
            el(ToggleControl, {
              label: 'Show "Generate AI summary" btn',
              checked: attrs.showSummary,
              onChange: function (v) {
                setAttr({ showSummary: v });
              },
            }),
          ),
          el(
            PanelBody,
            { title: "Labels", initialOpen: false },
            el(TextControl, {
              label: "Widget heading",
              value: attrs.title,
              onChange: function (v) {
                setAttr({ title: v });
              },
            }),
            el(TextControl, {
              label: "Button text",
              value: attrs.buttonText,
              onChange: function (v) {
                setAttr({ buttonText: v });
              },
            }),
          ),
          el(
            PanelBody,
            { title: "Appearance", initialOpen: false },
            el(TextControl, {
              label: "Extra CSS class",
              value: attrs.className,
              onChange: function (v) {
                setAttr({ className: v });
              },
            }),
          ),
        ),
        el(Placeholder, {
          icon: "media-document",
          label: "ExtractIA Upload Widget",
          instructions: attrs.template
            ? "Template: " + attrs.template
            : "Configure the template in the sidebar. The upload widget will render on the frontend.",
        }),
      );
    },
    save: function () {
      return null;
    }, // server-side render
  });

  // ─────────────────────────────────────────────────────────────────────────────
  // Block: extractia/document-list
  // ─────────────────────────────────────────────────────────────────────────────
  registerBlockType("extractia/document-list", {
    title: "ExtractIA Document List",
    description: "Display a table of extracted documents for a template.",
    icon: "list-view",
    category: "widgets",
    attributes: {
      template: { type: "string", default: "" },
      limit: { type: "integer", default: 10 },
      fields: { type: "string", default: "" },
    },
    edit: function (props) {
      var attrs = props.attributes;
      var setAttr = props.setAttributes;

      var [templates, setTemplates] = wp.element.useState([]);
      wp.element.useEffect(function () {
        fetchJson("/templates").then(function (res) {
          setTemplates(Array.isArray(res) ? res : []);
        });
      }, []);

      var tplOptions = [{ label: "— select —", value: "" }].concat(
        templates.map(function (t) {
          return { label: t.label, value: t.id };
        }),
      );

      return el(
        "div",
        { className: "extractia-block-editor-wrap" },
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: "Document List Settings", initialOpen: true },
            el(SelectControl, {
              label: "Template",
              value: attrs.template,
              options: tplOptions,
              onChange: function (v) {
                setAttr({ template: v });
              },
            }),
            el(RangeControl, {
              label: "Max rows",
              value: attrs.limit,
              min: 1,
              max: 50,
              onChange: function (v) {
                setAttr({ limit: v });
              },
            }),
            el(TextControl, {
              label: "Fields to display (comma-separated)",
              value: attrs.fields,
              onChange: function (v) {
                setAttr({ fields: v });
              },
            }),
          ),
        ),
        el(Placeholder, {
          icon: "list-view",
          label: "ExtractIA Document List",
          instructions: attrs.template
            ? "Showing last " +
              attrs.limit +
              " docs from template: " +
              attrs.template
            : "Select a template in the sidebar.",
        }),
      );
    },
    save: function () {
      return null;
    },
  });

  // ─────────────────────────────────────────────────────────────────────────────
  // Block: extractia/ocr-tool
  // ─────────────────────────────────────────────────────────────────────────────
  registerBlockType("extractia/ocr-tool", {
    title: "ExtractIA OCR Tool",
    description: "Drop an image and run a custom AI OCR tool.",
    icon: "search",
    category: "widgets",
    attributes: {
      toolId: { type: "string", default: "" },
      title: { type: "string", default: "" },
      className: { type: "string", default: "" },
    },
    edit: function (props) {
      var attrs = props.attributes;
      var setAttr = props.setAttributes;

      var [tools, setTools] = wp.element.useState([]);
      wp.element.useEffect(function () {
        fetchJson("/ocr-tools").then(function (res) {
          setTools(Array.isArray(res) ? res : []);
        });
      }, []);

      var toolOptions = [{ label: "— select —", value: "" }].concat(
        tools.map(function (t) {
          return { label: t.name, value: t.id };
        }),
      );

      return el(
        "div",
        { className: "extractia-block-editor-wrap" },
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: "OCR Tool Settings", initialOpen: true },
            el(SelectControl, {
              label: "OCR Tool",
              value: attrs.toolId,
              options: toolOptions,
              onChange: function (v) {
                setAttr({ toolId: v });
              },
            }),
            el(TextControl, {
              label: "Widget title",
              value: attrs.title,
              onChange: function (v) {
                setAttr({ title: v });
              },
            }),
            el(TextControl, {
              label: "CSS class",
              value: attrs.className,
              onChange: function (v) {
                setAttr({ className: v });
              },
            }),
          ),
        ),
        el(Placeholder, {
          icon: "search",
          label: "ExtractIA OCR Tool",
          instructions: attrs.toolId
            ? "Tool ID: " + attrs.toolId
            : "Select an OCR tool in the sidebar.",
        }),
      );
    },
    save: function () {
      return null;
    },
  });

  // ─────────────────────────────────────────────────────────────────────────────
  // Block: extractia/usage-meter
  // ─────────────────────────────────────────────────────────────────────────────
  registerBlockType("extractia/usage-meter", {
    title: "ExtractIA Usage Meter",
    description: "Displays document quota and AI credit balance.",
    icon: "chart-bar",
    category: "widgets",
    attributes: {},
    edit: function () {
      return el(Placeholder, {
        icon: "chart-bar",
        label: "ExtractIA Usage Meter",
        instructions:
          "Displays the current document quota and AI credit balance. Rendered server-side.",
      });
    },
    save: function () {
      return null;
    },
  });
})();
