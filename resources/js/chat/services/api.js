import { ENDPOINTS } from '../config';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function post(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrf(),
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: new URLSearchParams(body)
  });
  const data = await res.json();
  return { ok: res.ok, data, status: res.status };
}
async function get(url) {
  const res = await fetch(url, { headers: { 'Accept': 'application/json' }});
  const data = await res.json();
  return { ok: res.ok, data, status: res.status };
}

export const api = {
  send: (payload) => post(ENDPOINTS.send, payload),
  stats: () => get(ENDPOINTS.stats),
  listProjects: () => get(ENDPOINTS.projects),
  listMessages: (pid) => get(ENDPOINTS.messages(pid)),
  createProject: (path) => post(ENDPOINTS.projects, { path }),
  createFolder: (path) => post(ENDPOINTS.folders, { path }),
};
