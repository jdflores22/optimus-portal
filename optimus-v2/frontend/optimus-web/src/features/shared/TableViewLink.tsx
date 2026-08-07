import type { ReactNode } from 'react';
import { Button, type ButtonProps, type SxProps, type Theme } from '@mui/material';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import { Link as RouterLink, type To } from 'react-router-dom';

/** Standard header label for table action columns. */
export const TABLE_ACTIONS_HEADER = 'Actions';

/** Shared table action styling — use for every control in Action columns. */
export const tableActionSx: SxProps<Theme> = {
  textTransform: 'none',
  fontWeight: 600,
  whiteSpace: 'nowrap',
  minWidth: 'auto',
};

type TableViewLinkProps = {
  to: To;
};

/** Primary row action: navigate to a detail/review page. */
export function TableViewLink({ to }: TableViewLinkProps) {
  return (
    <Button
      size="small"
      component={RouterLink}
      to={to}
      startIcon={<VisibilityOutlinedIcon sx={{ fontSize: 16 }} />}
      sx={tableActionSx}
    >
      View
    </Button>
  );
}

type TableViewButtonProps = {
  onClick: () => void;
};

/** Same as TableViewLink but opens a dialog or inline panel. */
export function TableViewButton({ onClick }: TableViewButtonProps) {
  return (
    <Button
      size="small"
      onClick={onClick}
      startIcon={<VisibilityOutlinedIcon sx={{ fontSize: 16 }} />}
      sx={tableActionSx}
    >
      View
    </Button>
  );
}

type TableActionButtonProps = {
  label: string;
  onClick?: () => void;
  to?: To;
  icon?: ReactNode;
  color?: ButtonProps['color'];
  disabled?: boolean;
};

/** Secondary row actions (Release, Generate, etc.) — same size/weight as View. */
export function TableActionButton({ label, onClick, to, icon, color, disabled }: TableActionButtonProps) {
  if (to) {
    return (
      <Button
        size="small"
        component={RouterLink}
        to={to}
        color={color}
        disabled={disabled}
        startIcon={icon}
        sx={tableActionSx}
      >
        {label}
      </Button>
    );
  }

  return (
    <Button
      size="small"
      onClick={onClick}
      color={color}
      disabled={disabled}
      startIcon={icon}
      sx={tableActionSx}
    >
      {label}
    </Button>
  );
}
