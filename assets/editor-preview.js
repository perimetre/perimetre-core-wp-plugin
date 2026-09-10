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

    // Reload once a save or autosave completes: the frontend's `asPreview`
    // read returns the newest revision, which is what Gutenberg just wrote.
    useEffect(
      function () {
        if (wasSaving.current && !isSaving) {
          reload();
        }
        wasSaving.current = isSaving;
      },
      [isSaving]
    );

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
