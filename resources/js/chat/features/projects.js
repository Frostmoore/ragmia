// resources/js/chat/features/projects.js
import { api } from '../services/api';

export function attachProjects(store) {
  store.isOpen = function(id) {
    return !!this.openFolderIds[id];
  };

  store.toggleFolder = function(id) {
    this.openFolderIds[id] = !this.openFolderIds[id];
  };

  store.filteredNoFolder = function() {
    const q = (this.search || '').trim().toLowerCase();
    if (!q) return this.projectsNoFolder;
    return (this.projectsNoFolder || []).filter(p => p.path.toLowerCase().includes(q));
  };

  store.filteredProjects = function(arr) {
    const q = (this.search || '').trim().toLowerCase();
    if (!q) return arr;
    return (arr || []).filter(p => p.path.toLowerCase().includes(q));
  };

  store.findProjectInTree = function(path) {
    for (const p of (this.projectsNoFolder || [])) if (p.path === path) return p;
    const stack = [...(this.folders || [])];
    while (stack.length) {
      const f = stack.shift();
      for (const p of (f.projects || [])) if (p.path === path) return p;
      (f.children || []).forEach(ch => stack.push(ch));
    }
    return null;
  };

  store.openProjectTab = async function(project) {
    const existing = this.tabs.find(t => t.project_id === project.id);
    if (existing) {
      this.activateTab(existing.id);
      await this.ensureLoaded(existing);
      return;
    }

    const id = crypto.randomUUID();
    const tab = {
      id,
      title: project.path.split('/').pop(),
      path: project.path,
      project_id: project.id,
      messages: [],
      _loaded: false
    };
    this.tabs.push(tab);
    this.activeTabId = id;
    this.persistTabs();

    await this.ensureLoaded(tab);
  };

  store.promptNewProject = function() {
    const path = prompt('Path progetto (es. Consorzio/ScuoleGuida oppure SoloNome):');
    if (!path) return;
    this.createProject(path);
  };

  store.createProject = async function(path) {
    const { ok, data, status } = await api.createProject(path);
    if (!ok) {
      alert('Errore creazione progetto: ' + (data?.error || `HTTP ${status}`));
      return;
    }
    await this.reloadTree();
    const proj = this.findProjectInTree(data.project?.path);
    if (proj) this.openProjectTab(proj);
  };

  store.promptNewFolder = function() {
    const path = prompt('Path cartella (es. Consorzio o Consorzio/Sub):');
    if (!path) return;
    this.createFolder(path);
  };

  store.createFolder = async function(path) {
    const { ok, data, status } = await api.createFolder(path);
    if (!ok) {
      alert('Errore creazione cartella: ' + (data?.error || `HTTP ${status}`));
      return;
    }
    this.folders = data.tree?.folders || [];
    this.projectsNoFolder = data.tree?.projectsNoFolder || [];
    if (data.folder?.id) this.openFolderIds[data.folder.id] = true;
  };

  store.reloadTree = async function() {
    const { ok, data } = await api.listProjects();
    if (!ok) return;
    this.folders = data.folders || [];
    this.projectsNoFolder = data.projectsNoFolder || [];
  };
}
