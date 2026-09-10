/**
 * Side-by-side frontend preview for the block editor. Registered by
 * Perimetre\Core\Preview\EditorPreviewPane; reads the signed preview URL from
 * `window.perimetreEditorPreview.previewUrl`.
 *
 * Plain ES5 on the `wp.*` globals — no build step. Requires WP 6.7+
 * (`wp.editor.PluginSidebar` / `PluginPreviewMenuItem`).
 */
(function (wp) {
  if (!wp || !wp.plugins || !wp.editor || !wp.editor.PluginSidebar) {
    return;
  }

  var config = window.perimetreEditorPreview || {};
  if (!config.previewUrl) {
    return;
  }

  var el = wp.element.createElement;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var useSelect = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var Button = wp.components.Button;
  var __ = wp.i18n.__;

  /**
   * How long to wait after the user leaves a field or a block before forcing an
   * autosave. Long enough to coalesce tabbing through several fields into one
   * save, short enough that the pane feels live.
   */
  var AUTOSAVE_DEBOUNCE = 600;

  var PLUGIN = 'perimetre-core-editor-preview';
  var SIDEBAR = 'frontend-preview';
  var AREA = PLUGIN + '/' + SIDEBAR;
  var BODY_CLASS = 'perimetre-editor-preview-open';

  function Pane() {
    var frame = useRef(null);
    var wasSaving = useRef(false);

    var isSaving = useSelect(function (select) {
      var editor = select('core/editor');
      return editor.isSavingPost() || editor.isAutosavingPost();
    }, []);

    // Widen the sidebar while this pane is open. `PluginSidebar` only mounts
    // its children while it is the active complementary area, so mount /
    // unmount IS the open state — no store lookup needed.
    useEffect(function () {
      document.body.classList.add(BODY_CLASS);
      return function () {
        document.body.classList.remove(BODY_CLASS);
      };
    }, []);

    // Reload once a save or autosave completes: the frontend reads the newest
    // revision, which is what Gutenberg just wrote.
    useEffect(
      function () {
        if (wasSaving.current && !isSaving) {
          reload();
        }
        wasSaving.current = isSaving;
      },
      [isSaving]
    );

    // Force an autosave when the user leaves a field or moves off a block, so
    // the pane tracks edits instead of waiting out Gutenberg's autosave
    // interval. The frontend can only ever show what has been written to the
    // database — it reads the newest revision — so "refresh the preview" means
    // "autosave first, then reload", which the effect above already handles.
    //
    // Two listeners cover the whole editor without touching a single block:
    //
    //   - a `core/block-editor` store subscription fires when the selected
    //     block changes, which is what "left this block" means. Works for
    //     content typed in the canvas even when the canvas is iframed, since
    //     it is store state, not a DOM event.
    //   - one delegated `focusout` on the document (capture phase) covers the
    //     ACF fields, which in Blocks v3 live in the block sidebar and the
    //     slide-out modal — i.e. in this document, not the canvas iframe.
    //
    // Only while the pane is mounted: an editor who never opens the preview
    // keeps WordPress's stock autosave cadence.
    useEffect(function () {
      var timer = null;
      var lastBlockId = wp.data
        .select('core/block-editor')
        .getSelectedBlockClientId();

      function autosaveIfNeeded() {
        var editor = wp.data.select('core/editor');
        // A post that has never been saved is an `auto-draft` WordPress
        // created when the editor was opened. Autosaving it would turn an
        // abandoned "Add New" into a real draft behind the user's back, so
        // leave it alone — the frontend shows its "nothing to preview yet"
        // state until the author saves the draft themselves.
        if (editor.isEditedPostNew && editor.isEditedPostNew()) {
          return;
        }
        // Nothing to write, or a save is already in flight. `autosave()` is a
        // no-op in both cases, but skipping keeps the intent obvious.
        if (!editor.isEditedPostDirty()) {
          return;
        }
        if (editor.isSavingPost() || editor.isAutosavingPost()) {
          return;
        }
        // Respect a lock some other plugin took out (an incomplete required
        // field, a media upload in progress).
        if (editor.isPostSavingLocked && editor.isPostSavingLocked()) {
          return;
        }
        wp.data.dispatch('core/editor').autosave();
      }

      function schedule() {
        if (timer) {
          clearTimeout(timer);
        }
        timer = setTimeout(autosaveIfNeeded, AUTOSAVE_DEBOUNCE);
      }

      var unsubscribe = wp.data.subscribe(function () {
        var id = wp.data
          .select('core/block-editor')
          .getSelectedBlockClientId();
        if (id !== lastBlockId) {
          lastBlockId = id;
          schedule();
        }
      });

      function onFocusOut(event) {
        var target = event.target;
        if (!target || typeof target.matches !== 'function') {
          return;
        }
        if (
          target.matches(
            'input, textarea, select, [contenteditable="true"]'
          )
        ) {
          schedule();
        }
      }

      document.addEventListener('focusout', onFocusOut, true);

      return function () {
        if (timer) {
          clearTimeout(timer);
        }
        unsubscribe();
        document.removeEventListener('focusout', onFocusOut, true);
      };
    }, []);

    function reload() {
      if (frame.current) {
        // Same signed URL; the cache-buster forces the iframe to reload it and
        // the frontend ignores the extra param.
        frame.current.src = config.previewUrl + '&t=' + Date.now();
      }
    }

    return el(
      'div',
      { className: 'perimetre-editor-preview' },
      el(
        'div',
        { className: 'perimetre-editor-preview__bar' },
        el(
          Button,
          { variant: 'secondary', size: 'compact', onClick: reload },
          __('Refresh', 'perimetre-core')
        ),
        el(
          Button,
          {
            variant: 'tertiary',
            size: 'compact',
            href: config.previewUrl,
            target: '_blank',
            rel: 'noopener'
          },
          __('Open in a new tab', 'perimetre-core')
        ),
        el(
          'p',
          { className: 'perimetre-editor-preview__hint' },
          __('Updates after every save or autosave.', 'perimetre-core')
        )
      ),
      el('iframe', {
        ref: frame,
        className: 'perimetre-editor-preview__frame',
        src: config.previewUrl,
        title: __('Site preview', 'perimetre-core')
      })
    );
  }

  function OpenFromPreviewMenu() {
    var enable = useDispatch('core/interface').enableComplementaryArea;
    if (!wp.editor.PluginPreviewMenuItem) {
      return null;
    }
    return el(
      wp.editor.PluginPreviewMenuItem,
      {
        onClick: function () {
          enable('core', AREA);
        }
      },
      __('Side-by-side preview', 'perimetre-core')
    );
  }

  wp.plugins.registerPlugin(PLUGIN, {
    icon: 'welcome-view-site',
    render: function () {
      return el(
        wp.element.Fragment,
        null,
        el(
          wp.editor.PluginSidebar,
          {
            name: SIDEBAR,
            title: __('Site preview', 'perimetre-core'),
            icon: 'welcome-view-site'
          },
          el(Pane)
        ),
        el(
          wp.editor.PluginSidebarMoreMenuItem,
          { target: SIDEBAR, icon: 'welcome-view-site' },
          __('Site preview', 'perimetre-core')
        ),
        el(OpenFromPreviewMenu)
      );
    }
  });
})(window.wp);
