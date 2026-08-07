import { ReactNode } from 'react';
import { Box, Stack, Typography } from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { OptimusLogo } from '../../shared/OptimusLogo';
import { ColorModeToggle } from '../../shared/ColorModeToggle';
import { authPanelGradient } from '../../shared/theme';

const HIGHLIGHTS = ['Brokers', 'Consignees', 'Shipping lines', 'Accreditation', 'Manifests'] as const;

type AuthSplitLayoutProps = {
  title: string;
  subtitle?: ReactNode;
  maxWidth?: number;
  children: ReactNode;
};

export function AuthSplitLayout({
  title,
  subtitle,
  maxWidth = 420,
  children,
}: AuthSplitLayoutProps) {
  const theme = useTheme();
  const mode = theme.palette.mode;

  return (
    <Box
      minHeight="100vh"
      display="grid"
      gridTemplateColumns={{ xs: '1fr', md: '1fr 1fr' }}
      sx={{ bgcolor: 'background.paper' }}
    >
      <Box
        sx={{
          display: { xs: 'none', md: 'flex' },
          alignItems: 'center',
          justifyContent: 'center',
          px: { md: 6, lg: 10 },
          py: 6,
          bgcolor: mode === 'dark' ? 'background.default' : 'grey.100',
          backgroundImage: authPanelGradient(mode),
        }}
      >
        <Stack spacing={3} maxWidth={480} alignItems="flex-start">
          <OptimusLogo size="xl" />
          <Box>
            <Typography
              variant="h3"
              fontWeight={700}
              color="text.primary"
              sx={{ fontSize: { md: '2.25rem', lg: '2.75rem' }, lineHeight: 1.2 }}
            >
              Welcome to OPTIMUS
            </Typography>
            <Typography variant="body1" color="text.secondary" mt={2} sx={{ lineHeight: 1.7 }}>
              Operational platform for consignees, brokers, and shipping lines — accreditation,
              manifests, and relationships in one place so your team can move freight with
              confidence.
            </Typography>
          </Box>
          <Stack direction="row" flexWrap="wrap" useFlexGap spacing={1.5} pt={1}>
            {HIGHLIGHTS.map((label) => (
              <Typography
                key={label}
                variant="caption"
                fontWeight={600}
                letterSpacing={0.6}
                sx={{
                  color: 'text.secondary',
                  textTransform: 'uppercase',
                  opacity: 0.85,
                  '&:not(:last-child)::after': {
                    content: '"·"',
                    ml: 1.5,
                    opacity: 0.5,
                  },
                }}
              >
                {label}
              </Typography>
            ))}
          </Stack>
        </Stack>
      </Box>

      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          px: { xs: 2.5, sm: 4 },
          py: { xs: 4, md: 6 },
          bgcolor: 'background.paper',
          position: 'relative',
        }}
      >
        <Box sx={{ position: 'absolute', top: 16, right: 16 }}>
          <ColorModeToggle size="small" />
        </Box>

        <Stack spacing={3} width="100%" maxWidth={maxWidth}>
          <Box>
            <Box mb={2.5}>
              <OptimusLogo size="sm" />
            </Box>

            <Typography variant="h4" fontWeight={700}>
              {title}
            </Typography>
            {subtitle && (
              <Typography variant="body2" color="text.secondary" mt={1} component="div">
                {subtitle}
              </Typography>
            )}
          </Box>

          {children}
        </Stack>
      </Box>
    </Box>
  );
}
