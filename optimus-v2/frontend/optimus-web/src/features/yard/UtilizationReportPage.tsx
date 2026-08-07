import { Alert, Button, Chip, Table, TableBody, TableCell, TableHead, TableRow } from '@mui/material';
import { useState } from 'react';
import { useExportUtilizationMutation, useGetUtilizationQuery } from '../../app/api';
import { API_BASE_URL } from '../../shared/types';
import { WorkflowPage, WorkflowSection } from '../shared/WorkflowPage';

export function UtilizationReportPage() {
  const { data = [], refetch } = useGetUtilizationQuery();
  const [exportReport] = useExportUtilizationMutation();
  const [csv, setCsv] = useState<string | null>(null);
  const [pdf, setPdf] = useState<string | null>(null);
  const totalAllocated = data.reduce((sum, row) => sum + row.allocatedTeu, 0);
  const totalUsed = data.reduce((sum, row) => sum + row.usedTeu, 0);
  const totalPending = data.reduce((sum, row) => sum + row.pendingPreAdvice, 0);

  return (
    <WorkflowPage
      eyebrow="Capacity oversight"
      title="Port and CY utilization"
      subtitle="Review capacity pressure across terminals before yard congestion creates downstream release delays."
      chips={
        <>
          <Chip size="small" label={`${data.length} terminals`} color="primary" />
          {pdf && <Chip size="small" label="PDF exported" color="success" />}
        </>
      }
      actions={
        <>
          <Button variant="outlined" onClick={() => refetch()}>
            Refresh
          </Button>
          <Button
            variant="contained"
            onClick={async () => {
              const r = await exportReport().unwrap();
              setCsv(r.csv);
              setPdf(r.pdfPath);
            }}
          >
            Export CSV/PDF
          </Button>
          {pdf && (
            <Button href={`${API_BASE_URL}${pdf}`} target="_blank">
              Open PDF
            </Button>
          )}
        </>
      }
      stats={[
        { label: 'Allocated TEU', value: totalAllocated, hint: 'Capacity assigned to terminals', tone: 'primary' },
        { label: 'Used TEU', value: totalUsed, hint: 'Current occupancy', tone: 'info' },
        { label: 'Utilization', value: totalAllocated ? `${Math.round((totalUsed / totalAllocated) * 100)}%` : '0%', hint: 'Overall load factor', tone: totalAllocated && totalUsed / totalAllocated > 0.8 ? 'warning' : 'success' },
        { label: 'Pending pre-advice', value: totalPending, hint: 'Upcoming yard intake', tone: 'warning' },
      ]}
    >
      {csv && <Alert severity="info">CSV ready ({csv.split('\n').length - 1} data rows)</Alert>}
      <WorkflowSection title="Terminal utilization table" subtitle="Use this view to spot where return volume is exceeding available headroom.">
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Terminal</TableCell>
              <TableCell>Allocated</TableCell>
              <TableCell>Used</TableCell>
              <TableCell>Utilization</TableCell>
              <TableCell>Available</TableCell>
              <TableCell>At terminal</TableCell>
              <TableCell>Pending PA</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {data.map((r) => (
              <TableRow key={r.terminalId}>
                <TableCell>{r.terminalName}</TableCell>
                <TableCell>{r.allocatedTeu}</TableCell>
                <TableCell>{r.usedTeu}</TableCell>
                <TableCell>{r.utilizationPercent}%</TableCell>
                <TableCell>{r.availableForReturn}</TableCell>
                <TableCell>{r.atTerminal}</TableCell>
                <TableCell>{r.pendingPreAdvice}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </WorkflowSection>
    </WorkflowPage>
  );
}
