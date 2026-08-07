import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import type { UserDto } from '../shared/types';

type AuthState = {
  accessToken: string | null;
  refreshToken: string | null;
  user: UserDto | null;
};

const storageKey = 'optimus.v2.auth';

function loadAuth(): AuthState {
  try {
    const raw = localStorage.getItem(storageKey);
    if (!raw) {
      return { accessToken: null, refreshToken: null, user: null };
    }
    return JSON.parse(raw) as AuthState;
  } catch {
    return { accessToken: null, refreshToken: null, user: null };
  }
}

const initialState: AuthState = loadAuth();

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    setCredentials: (
      state,
      action: PayloadAction<{ accessToken: string; refreshToken: string; user: UserDto }>,
    ) => {
      state.accessToken = action.payload.accessToken;
      state.refreshToken = action.payload.refreshToken;
      state.user = action.payload.user;
      localStorage.setItem(storageKey, JSON.stringify(state));
    },
    logout: (state) => {
      state.accessToken = null;
      state.refreshToken = null;
      state.user = null;
      localStorage.removeItem(storageKey);
    },
  },
});

export const { setCredentials, logout } = authSlice.actions;
export default authSlice.reducer;
