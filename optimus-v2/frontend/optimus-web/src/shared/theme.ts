import { createTheme, type PaletteMode, type ThemeOptions } from '@mui/material/styles';

const brand = {
  primary: '#0B3D5C',
  primaryLight: '#1A5A82',
  primaryDark: '#072A40',
  secondary: '#C45C26',
  secondaryLight: '#E07A45',
};

const shared: ThemeOptions = {
  typography: {
    fontFamily: '"IBM Plex Sans", "Segoe UI", sans-serif',
    h4: { fontWeight: 700 },
    h5: { fontWeight: 600 },
  },
  shape: { borderRadius: 8 },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        body: {
          transition: 'background-color 0.2s ease, color 0.2s ease',
        },
      },
    },
    MuiPaper: {
      styleOverrides: {
        root: {
          backgroundImage: 'none',
        },
        rounded: {
          borderRadius: 8,
        },
      },
    },
    MuiButton: {
      styleOverrides: {
        root: {
          borderRadius: 8,
          fontWeight: 600,
        },
      },
    },
    MuiOutlinedInput: {
      styleOverrides: {
        root: {
          borderRadius: 8,
        },
      },
    },
    MuiFilledInput: {
      styleOverrides: {
        root: {
          borderRadius: 8,
        },
      },
    },
    MuiInputBase: {
      styleOverrides: {
        root: {
          borderRadius: 8,
        },
      },
    },
    MuiSelect: {
      styleOverrides: {
        outlined: {
          borderRadius: 8,
        },
      },
    },
    MuiAutocomplete: {
      styleOverrides: {
        paper: {
          borderRadius: 8,
        },
      },
    },
    MuiChip: {
      styleOverrides: {
        root: {
          borderRadius: 8,
        },
      },
    },
    MuiAlert: {
      styleOverrides: {
        root: {
          borderRadius: 8,
        },
      },
    },
    MuiDialog: {
      styleOverrides: {
        paper: {
          borderRadius: 12,
        },
      },
    },
    MuiMenu: {
      styleOverrides: {
        paper: {
          borderRadius: 8,
        },
      },
    },
    MuiCard: {
      styleOverrides: {
        root: {
          borderRadius: 8,
        },
      },
    },
    MuiAppBar: {
      defaultProps: {
        color: 'inherit',
      },
    },
  },
};

function paletteFor(mode: PaletteMode): ThemeOptions['palette'] {
  if (mode === 'dark') {
    return {
      mode: 'dark',
      primary: {
        main: '#5BA3C9',
        light: '#8BC4DE',
        dark: brand.primaryLight,
        contrastText: '#061820',
      },
      secondary: {
        main: '#E07A45',
        light: '#F0A078',
        dark: brand.secondary,
        contrastText: '#1A0E08',
      },
      background: {
        default: '#0D1419',
        paper: '#152028',
      },
      text: {
        primary: '#E8EEF2',
        secondary: '#9AADB8',
        disabled: '#5C6F7A',
      },
      divider: 'rgba(154, 173, 184, 0.16)',
      action: {
        hover: 'rgba(91, 163, 201, 0.08)',
        selected: 'rgba(91, 163, 201, 0.14)',
        disabled: 'rgba(154, 173, 184, 0.3)',
        disabledBackground: 'rgba(154, 173, 184, 0.12)',
      },
    };
  }

  return {
    mode: 'light',
    primary: {
      main: brand.primary,
      light: brand.primaryLight,
      dark: brand.primaryDark,
      contrastText: '#FFFFFF',
    },
    secondary: {
      main: brand.secondary,
      light: brand.secondaryLight,
      dark: '#9A4518',
      contrastText: '#FFFFFF',
    },
    background: {
      default: '#F4F7FA',
      paper: '#FFFFFF',
    },
    text: {
      primary: '#1A2330',
      secondary: '#5A6B7A',
    },
    divider: 'rgba(11, 61, 92, 0.12)',
  };
}

export function createAppTheme(mode: PaletteMode) {
  return createTheme({
    ...shared,
    palette: paletteFor(mode),
  });
}

/** @deprecated Prefer createAppTheme(mode) via ColorModeProvider */
export const theme = createAppTheme('light');

export function pageHeroGradient(mode: PaletteMode): string {
  if (mode === 'dark') {
    return 'linear-gradient(135deg, rgba(91,163,201,0.14) 0%, rgba(21,32,40,1) 48%, rgba(224,122,69,0.1) 100%)';
  }
  return 'linear-gradient(135deg, rgba(11,61,92,0.08) 0%, #fff 45%, rgba(196,92,38,0.05) 100%)';
}

export function authPanelGradient(mode: PaletteMode): string {
  if (mode === 'dark') {
    return [
      'radial-gradient(ellipse 80% 60% at 20% 10%, rgba(91,163,201,0.16), transparent 55%)',
      'radial-gradient(ellipse 70% 50% at 90% 90%, rgba(224,122,69,0.12), transparent 50%)',
    ].join(', ');
  }
  return [
    'radial-gradient(ellipse 80% 60% at 20% 10%, rgba(11,61,92,0.08), transparent 55%)',
    'radial-gradient(ellipse 70% 50% at 90% 90%, rgba(196,92,38,0.07), transparent 50%)',
  ].join(', ');
}
