const TOKEN_KEY = 'erp_token';
const USER_KEY = 'erp_user';

export type AuthUser = {
  id: number;
  name: string;
  email: string;
};

export const auth = {
  getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  },
  setToken(t: string) {
    localStorage.setItem(TOKEN_KEY, t);
  },
  clearToken() {
    localStorage.removeItem(TOKEN_KEY);
  },
  getUser(): AuthUser | null {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? JSON.parse(raw) : null;
  },
  setUser(u: AuthUser) {
    localStorage.setItem(USER_KEY, JSON.stringify(u));
  },
  clearUser() {
    localStorage.removeItem(USER_KEY);
  },
  isLoggedIn() {
    return !!this.getToken();
  },
  logout() {
    this.clearToken();
    this.clearUser();
  },
};
