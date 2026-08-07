import { Box } from '@mui/material';
import optimusLogo from '../assets/optimus-logo.png';

type OptimusLogoProps = {
  /** Compact mark for nav bars vs larger brand lockups. */
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
  alt?: string;
};

const HEIGHTS: Record<NonNullable<OptimusLogoProps['size']>, number> = {
  xs: 28,
  sm: 40,
  md: 56,
  lg: 120,
  xl: 168,
};

const PADDING: Record<NonNullable<OptimusLogoProps['size']>, number> = {
  xs: 0.5,
  sm: 0.75,
  md: 1,
  lg: 1.25,
  xl: 1.5,
};

export function OptimusLogo({
  size = 'md',
  alt = 'OPTIMUS Shipping System',
}: OptimusLogoProps) {
  const height = HEIGHTS[size];

  return (
    <Box
      sx={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        bgcolor: '#FFFFFF',
        borderRadius: 2,
        p: PADDING[size],
        lineHeight: 0,
        boxShadow: '0 0 0 1px rgba(11,61,92,0.08)',
      }}
    >
      <Box
        component="img"
        src={optimusLogo}
        alt={alt}
        sx={{
          height,
          width: 'auto',
          maxWidth: '100%',
          display: 'block',
          objectFit: 'contain',
          background: 'transparent',
        }}
      />
    </Box>
  );
}

export const OPTIMUS_LOGO_SRC = optimusLogo;
