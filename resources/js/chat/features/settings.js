// resources/js/chat/features/settings.js
import { DEFAULT_MODEL, DEFAULT_COMPRESSOR } from '../config';

export function attachSettings(store) {
  store.setModel = function(v) {
    this.model = String(v || '').trim() || DEFAULT_MODEL;
    this.persistTabs();
  };

  store.setCompressModel = function(v) {
    this.compress_model = String(v || '').trim() || DEFAULT_COMPRESSOR;
    this.persistTabs();
  };

  store.setAutoContext = function(checked) {
    this.autoContext = !!checked;
    this.persistTabs();
  };

  store.setUseCompressor = function(checked) {
    this.useCompressor = !!checked;
    this.persistTabs();
  };
}
