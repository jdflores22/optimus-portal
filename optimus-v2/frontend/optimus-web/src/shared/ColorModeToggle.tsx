import { IconButton, Tooltip } from '@mui/material';
import DarkModeOutlinedIcon from '@mui/icons-material/DarkModeOutlined';
import LightModeOutlinedIcon from '@mui/icons-material/LightModeOutlined';
import { useColorMode } from './ColorModeProvider';

type ColorModeToggleProps = {
  size?: 'small' | 'medium';
  edge?: 'start' | 'end' | false;
};

export function ColorModeToggle({ size = 'medium', edge = false }: ColorModeToggleProps) {
  const { mode, toggleColorMode } = useColorMode();
  const isDark = mode === 'dark';

  return (
    <Tooltip title={isDark ? 'Switch to light mode' : 'Switch to dark mode'}>
      <IconButton
        onClick={toggleColorMode}
        edge={edge || undefined}
        size={size}
        aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
        sx={{
          color: 'text.secondary',
          border: 1,
          borderColor: 'divider',
          borderRadius: 2,
          width: size === 'small' ? 36 : 40,
          height: size === 'small' ? 36 : 40,
          '&:hover': {
            color: 'primary.main',
            borderColor: 'primary.main',
            bgcolor: 'action.hover',
          },
        }}
      >
        {isDark ? (
          <LightModeOutlinedIcon fontSize="small" />
        ) : (
          <DarkModeOutlinedIcon fontSize="small" />
        )}
      </IconButton>
    </Tooltip>
  );
}
