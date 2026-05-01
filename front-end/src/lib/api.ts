const API_BASE = '/api';

function getPassword(): string {
  return localStorage.getItem('panel_password') || '';
}

export function isAuthenticated(): boolean {
  return !!localStorage.getItem('panel_password');
}

export function setPassword(pw: string): void {
  localStorage.setItem('panel_password', pw);
}

export function clearPassword(): void {
  localStorage.removeItem('panel_password');
}

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      'X-Panel-Password': getPassword(),
      ...options?.headers,
    },
  });
  if (res.status === 401) {
    clearPassword();
    window.location.href = '/login';
    throw new Error('Unauthorized');
  }
  return res.json() as Promise<T>;
}

export const api = {
  status: () =>
    request<{ online: boolean; name?: string; map?: string; players?: number; maxplayers?: number }>(
      '/?api=status'
    ),
  players: () => request<Array<{ name: string; score: number; time: string }>>('/?api=players'),
  rcon: (cmd: string) => {
    const body = new URLSearchParams({ cmd });
    return request<{ success: boolean; output: string }>('/?api=rcon', { method: 'POST', body });
  },
  changelevel: (map: string) => {
    const body = new URLSearchParams({ map });
    return request<{ success: boolean; output: string }>('/?api=changelevel', { method: 'POST', body });
  },
  mapsList: () => request<string[]>('/?api=maps_list'),
  mapcycleGet: () => request<{ content: string }>('/?api=mapcycle_get'),
  mapcycleSave: (content: string) => {
    const body = new URLSearchParams({ content });
    return request<{ success: boolean; saved?: number; error?: string }>('/?api=mapcycle_save', {
      method: 'POST',
      body,
    });
  },
  mapsUpload: (file: File) => {
    const body = new FormData();
    body.append('mapfile', file);
    return request<{ success: boolean; name?: string; error?: string }>('/?api=maps_upload', {
      method: 'POST',
      body,
    });
  },
  botnamesGet: () => request<{ content: string; exists: boolean }>('/?api=botnames_get'),
  botnamesSave: (content: string) => {
    const body = new URLSearchParams({ content });
    return request<{ success: boolean; saved?: number; error?: string }>('/?api=botnames_save', {
      method: 'POST',
      body,
    });
  },
  botsApply: (quota: number, skill: number) => {
    const body = new URLSearchParams({ quota: String(quota), skill: String(skill) });
    return request<{ success: boolean; output: string }>('/?api=bots_apply', { method: 'POST', body });
  },
  botsKickall: () =>
    request<{ success: boolean; output: string }>('/?api=bots_kickall', { method: 'POST' }),
  settingsGet: () =>
    request<{ success: boolean; values: Record<string, string> }>('/?api=settings_get'),
  settingsSet: (values: Record<string, number>) => {
    const body = new URLSearchParams(
      Object.fromEntries(Object.entries(values).map(([k, v]) => [k, String(v)]))
    );
    return request<{ success: boolean; output: string }>('/?api=settings_set', {
      method: 'POST',
      body,
    });
  },
  checkAuth: async (): Promise<boolean> => {
    try {
      const res = await fetch(`${API_BASE}/?api=status`, {
        headers: { 'X-Panel-Password': getPassword() },
      });
      return res.status !== 401;
    } catch {
      return false;
    }
  },
};
