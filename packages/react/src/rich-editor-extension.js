// Share Filament's TipTap runtime: no second editor or ProseMirror instance.
export default function () {
    return window.FilamentRichEditor.tiptap.core.Extension.create({
        name: 'studioResponsiveImages',
        addGlobalAttributes() {
            return [{types: ['image'], attributes: {
                srcset: {default: null, parseHTML: element => element.getAttribute('srcset')},
                sizes: {default: null, parseHTML: element => element.getAttribute('sizes')},
            }}];
        },
    });
}
