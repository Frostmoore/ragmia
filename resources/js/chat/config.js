export const LS_KEY = 'chatTabsV5';
export const DEFAULT_MODEL = 'openai:gpt-5';
export const DEFAULT_COMPRESSOR = 'openai:gpt-4o-mini';

export const ENDPOINTS = {
  send: '/send',
  stats: '/chat/stats',
  projects: '/api/projects',
  messages: (projectId) => `/api/messages?project_id=${projectId}`,
  folders: '/api/folders',
};
