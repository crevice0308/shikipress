(function (wp) {
  'use strict';
  if (!wp || !wp.blocks || !wp.element || !wp.blockEditor) return;
  const el = wp.element.createElement;
  wp.blocks.registerBlockType('shikipress/ad-slot', {
    apiVersion: 3, title: 'ShikiPress 広告枠', icon: 'megaphone', category: 'widgets',
    attributes: {slot: {type: 'string', default: ''}},
    supports: {html: false, reusable: false},
    edit: function (props) {
      const choices = [{value: '', label: '広告枠を選択'}].concat(window.shikiAdSlots || []);
      return el('div', wp.blockEditor.useBlockProps({className: 'shiki-ad-editor'}),
        el(wp.components.Placeholder, {label: '広告枠 / PR', instructions: 'この編集画面では外部広告・計測タグを実行しません。枠の設定と状態はShikiPress → 広告で管理します。'},
          el(wp.components.SelectControl, {label: '掲載する広告枠', value: props.attributes.slot, options: choices, onChange: function (value) { props.setAttributes({slot: value}); }})));
    },
    save: function () { return null; }
  });
})(window.wp);
