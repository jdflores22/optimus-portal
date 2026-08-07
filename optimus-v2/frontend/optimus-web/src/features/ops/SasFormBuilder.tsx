import AddOutlinedIcon from '@mui/icons-material/AddOutlined';
import ContentCopyOutlinedIcon from '@mui/icons-material/ContentCopyOutlined';
import DeleteOutlineOutlinedIcon from '@mui/icons-material/DeleteOutlineOutlined';
import DragIndicatorIcon from '@mui/icons-material/DragIndicator';
import KeyboardArrowUpIcon from '@mui/icons-material/KeyboardArrowUp';
import KeyboardArrowDownIcon from '@mui/icons-material/KeyboardArrowDown';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import VisibilityOffOutlinedIcon from '@mui/icons-material/VisibilityOffOutlined';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControlLabel,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  MenuItem,
  Paper,
  Radio,
  RadioGroup,
  Stack,
  Tab,
  Tabs,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import {
  useActivateFormMutation,
  useCreateFormMutation,
  useDeleteFormMutation,
  usePublishFormMutation,
  useUpdateFormFieldsMutation,
} from '../../app/api';
import type { FormConfigurationDto, SasFieldValidation, SasFormField } from '../../shared/types';
import {
  COLUMN_SPAN_OPTIONS,
  FIELD_TEMPLATE_GROUPS,
  SAS_FIELD_TYPES,
  applyInputRestriction,
  createFieldFromTemplate,
  isChoiceType,
  isFileType,
  isLayoutField,
  parseFormFields,
  resolveColumnSpan,
  serializeFormFields,
  slugifyFieldId,
  type FieldTemplate,
} from '../../shared/formSchema';
import { DynamicFormFields, type DynamicFormValues } from './DynamicFormFields';

function errMsg(e: unknown, fallback: string): string {
  return (e as { data?: { message?: string } })?.data?.message ?? fallback;
}

type Props = {
  forms: FormConfigurationDto[];
  onRefresh: () => void;
  onMessage: (m: string) => void;
  onError: (m: string) => void;
};

export function SasFormBuilder({ forms, onRefresh, onMessage, onError }: Props) {
  const [tab, setTab] = useState<'Broker' | 'Consignee'>('Broker');
  const [editingId, setEditingId] = useState<string | null>(null);
  const [fields, setFields] = useState<SasFormField[]>([]);
  const [selectedIndex, setSelectedIndex] = useState<number | null>(null);
  const [previewValues, setPreviewValues] = useState<DynamicFormValues>({});
  const [showPreview, setShowPreview] = useState(true);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleteFormTarget, setDeleteFormTarget] = useState<FormConfigurationDto | null>(null);
  const [dragFromIndex, setDragFromIndex] = useState<number | null>(null);
  const [dragOverIndex, setDragOverIndex] = useState<number | null>(null);
  const dragFromRef = useRef<number | null>(null);
  const [createForm, { isLoading: creating }] = useCreateFormMutation();
  const [updateFields, { isLoading: saving }] = useUpdateFormFieldsMutation();
  const [publishForm] = usePublishFormMutation();
  const [activateForm] = useActivateFormMutation();
  const [deleteForm, { isLoading: deleting }] = useDeleteFormMutation();

  const typedForms = useMemo(() => forms.filter((f) => f.type === tab), [forms, tab]);
  const editing = typedForms.find((f) => f.id === editingId) ?? null;
  const canEdit = Boolean(editing && editing.status !== 'Active');
  const selected = selectedIndex != null ? fields[selectedIndex] : null;

  useEffect(() => {
    if (editing) {
      const parsed = parseFormFields(editing.fieldsJson);
      setFields(parsed);
      setSelectedIndex(parsed.length ? 0 : null);
    } else {
      setFields([]);
      setSelectedIndex(null);
    }
  }, [editing?.id, editing?.fieldsJson]);

  useEffect(() => {
    const initial: DynamicFormValues = {};
    fields.forEach((f) => {
      if (isLayoutField(f.type)) return;
      if (f.type === 'multi_select') initial[f.id] = [];
      else if (['checkbox', 'toggle', 'terms'].includes(f.type)) initial[f.id] = false;
      else initial[f.id] = '';
    });
    setPreviewValues(initial);
  }, [fields]);

  const startEdit = (form: FormConfigurationDto) => setEditingId(form.id);

  const addTemplate = (template: FieldTemplate) => {
    if (!canEdit) {
      onError('Open a Draft/Published form, or create a new version from an Active form.');
      return;
    }
    const next = [...fields, createFieldFromTemplate(template, fields.length + 1)];
    setFields(next.map((f, i) => ({ ...f, order: i + 1 })));
    setSelectedIndex(next.length - 1);
  };

  const patchSelected = (patch: Partial<SasFormField>) => {
    if (selectedIndex == null || !canEdit) return;
    setFields((prev) =>
      prev.map((f, i) => {
        if (i !== selectedIndex) return f;
        const next = { ...f, ...patch };
        if (patch.type && patch.type !== f.type) {
          const needsOptions = isChoiceType(patch.type);
          next.validation = needsOptions
            ? { options: f.options ?? { option_1: 'Option 1', option_2: 'Option 2' } }
            : {};
          next.options = needsOptions
            ? (f.options ?? { option_1: 'Option 1', option_2: 'Option 2' })
            : undefined;
          if (isLayoutField(patch.type)) next.required = false;
        }
        if (patch.options) {
          next.validation = { ...(next.validation ?? {}), options: patch.options };
        }
        if (patch.validation) {
          next.validation = { ...(f.validation ?? {}), ...patch.validation };
          if (patch.validation.options) next.options = patch.validation.options;
        }
        return next;
      }),
    );
  };

  const patchValidation = (patch: Partial<SasFieldValidation>) => {
    if (selectedIndex == null || !canEdit || !selected) return;
    const validation = { ...(selected.validation ?? {}), ...patch };
    // clear undefined keys marked for deletion via explicit undefined? keep simple merge
    Object.keys(patch).forEach((k) => {
      if ((patch as Record<string, unknown>)[k] === undefined) {
        delete (validation as Record<string, unknown>)[k];
      }
    });
    patchSelected({
      validation,
      ...(patch.options ? { options: patch.options } : {}),
    });
  };

  const moveSelected = (dir: -1 | 1) => {
    if (selectedIndex == null || !canEdit) return;
    const target = selectedIndex + dir;
    if (target < 0 || target >= fields.length) return;
    reorderFields(selectedIndex, target);
  };

  const reorderFields = (from: number, to: number) => {
    if (!canEdit || from === to || from < 0 || to < 0 || from >= fields.length || to >= fields.length) {
      return;
    }
    const next = [...fields];
    const [row] = next.splice(from, 1);
    next.splice(to, 0, row);
    setFields(next.map((f, i) => ({ ...f, order: i + 1 })));
    setSelectedIndex(to);
  };

  const onDragStart = (index: number) => {
    if (!canEdit) return;
    dragFromRef.current = index;
    setDragFromIndex(index);
  };

  const onDragOver = (e: React.DragEvent, index: number) => {
    if (!canEdit || dragFromRef.current == null) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (dragOverIndex !== index) setDragOverIndex(index);
  };

  const onDrop = (index: number) => {
    if (!canEdit) return;
    const from = dragFromRef.current;
    dragFromRef.current = null;
    setDragFromIndex(null);
    setDragOverIndex(null);
    if (from == null || from === index) return;
    reorderFields(from, index);
  };

  const onDragEnd = () => {
    dragFromRef.current = null;
    setDragFromIndex(null);
    setDragOverIndex(null);
  };

  const confirmDeleteField = () => {
    if (selectedIndex == null || !canEdit) return;
    const next = fields.filter((_, i) => i !== selectedIndex);
    setFields(next.map((f, i) => ({ ...f, order: i + 1 })));
    setSelectedIndex(next.length ? Math.min(selectedIndex, next.length - 1) : null);
    setDeleteOpen(false);
  };

  const duplicateFieldAt = (index: number) => {
    if (!canEdit) return;
    const source = fields[index];
    if (!source) return;

    const existing = new Set(fields.map((f) => f.id));
    const base = slugifyFieldId(source.label || source.type || 'field');
    let n = fields.length + 1;
    let id = `${base}_${n}`;
    while (existing.has(id)) {
      n += 1;
      id = `${base}_${n}`;
    }

    const clone: SasFormField = {
      ...JSON.parse(JSON.stringify(source)) as SasFormField,
      id,
    };

    const next = [...fields];
    next.splice(index + 1, 0, clone);
    setFields(next.map((f, i) => ({ ...f, order: i + 1 })));
    setSelectedIndex(index + 1);
  };

  const onDeleteFormVersion = async () => {
    if (!deleteFormTarget) return;
    try {
      await deleteForm(deleteFormTarget.id).unwrap();
      if (editingId === deleteFormTarget.id) setEditingId(null);
      onMessage(`Deleted ${deleteFormTarget.name} v${deleteFormTarget.version}`);
      setDeleteFormTarget(null);
      onRefresh();
    } catch (e) {
      onError(errMsg(e, 'Could not delete form'));
    }
  };

  const onCreateDraft = async () => {
    try {
      const f = await createForm({
        name: `${tab} SAS Form`,
        type: tab,
        fieldsJson: serializeFormFields([
          createFieldFromTemplate(
            { type: 'text', name: 'Text', defaultLabel: 'Company Name' },
            1,
          ),
          createFieldFromTemplate({ type: 'text', name: 'Text', defaultLabel: 'TIN' }, 2),
        ]),
      }).unwrap();
      onMessage(`Draft ${f.name} v${f.version} created`);
      onRefresh();
      setEditingId(f.id);
    } catch (e) {
      onError(errMsg(e, 'Could not create form'));
    }
  };

  const onCloneVersion = async (source: FormConfigurationDto) => {
    try {
      const f = await createForm({
        name: source.name,
        type: source.type,
        fieldsJson: source.fieldsJson,
      }).unwrap();
      onMessage(`New draft v${f.version} created from v${source.version}`);
      onRefresh();
      setEditingId(f.id);
    } catch (e) {
      onError(errMsg(e, 'Could not create new version'));
    }
  };

  const onSaveFields = async () => {
    if (!editing) return;
    if (fields.length === 0) {
      onError('Add at least one field from Field Templates.');
      return;
    }
    try {
      await updateFields({ id: editing.id, fieldsJson: serializeFormFields(fields) }).unwrap();
      onMessage('Form structure saved');
      onRefresh();
    } catch (e) {
      onError(errMsg(e, 'Could not save fields'));
    }
  };

  const panelSx = {
    p: 2,
    border: 1,
    borderColor: 'divider',
    borderRadius: 2,
    bgcolor: 'background.paper',
    height: '100%',
    minHeight: 480,
    display: 'flex',
    flexDirection: 'column',
  } as const;

  return (
    <Stack spacing={2}>
      <Paper elevation={0} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2 }}>
        <Stack
          direction={{ xs: 'column', md: 'row' }}
          justifyContent="space-between"
          spacing={1.5}
          alignItems={{ md: 'center' }}
        >
          <Box>
            <Typography variant="h6" fontWeight={700}>
              Form Builder
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Mirrors V1 admin form builder — Field Templates, Form Structure, Field Properties, and
              Live Preview
            </Typography>
          </Box>
          <Tabs
            value={tab}
            onChange={(_, v) => {
              setTab(v);
              setEditingId(null);
            }}
          >
            <Tab value="Broker" label="Broker" />
            <Tab value="Consignee" label="Consignee" />
          </Tabs>
        </Stack>

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} mt={2} mb={2} flexWrap="wrap">
          <Button variant="contained" onClick={onCreateDraft} disabled={creating}>
            New {tab} draft
          </Button>
          {editing && canEdit && (
            <Button variant="outlined" onClick={onSaveFields} disabled={saving}>
              Save structure
            </Button>
          )}
          {editing && (
            <Button
              startIcon={showPreview ? <VisibilityOffOutlinedIcon /> : <VisibilityOutlinedIcon />}
              onClick={() => setShowPreview((v) => !v)}
            >
              {showPreview ? 'Hide preview' : 'Live Preview'}
            </Button>
          )}
          {editing?.status === 'Draft' && (
            <Button
              onClick={async () => {
                try {
                  await publishForm(editing.id).unwrap();
                  onMessage(`Published v${editing.version}`);
                  onRefresh();
                } catch (e) {
                  onError(errMsg(e, 'Publish failed'));
                }
              }}
            >
              Publish
            </Button>
          )}
          {editing && (editing.status === 'Published' || editing.status === 'Inactive') && (
            <Button
              variant="contained"
              color="success"
              onClick={async () => {
                try {
                  await activateForm(editing.id).unwrap();
                  onMessage(`Activated v${editing.version}`);
                  onRefresh();
                } catch (e) {
                  onError(errMsg(e, 'Activate failed'));
                }
              }}
            >
              Activate
            </Button>
          )}
        </Stack>

        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>Version</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Fields</TableCell>
              <TableCell align="right">Open</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {typedForms.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5}>
                  <Typography variant="body2" color="text.secondary">
                    No {tab} forms yet. Create a draft to open the builder.
                  </Typography>
                </TableCell>
              </TableRow>
            ) : (
              typedForms.map((f) => (
                <TableRow key={f.id} selected={editingId === f.id} hover>
                  <TableCell>{f.name}</TableCell>
                  <TableCell>v{f.version}</TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      label={f.status}
                      color={
                        f.status === 'Active'
                          ? 'success'
                          : f.status === 'Published'
                            ? 'info'
                            : 'default'
                      }
                    />
                  </TableCell>
                  <TableCell>{parseFormFields(f.fieldsJson).length}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end" flexWrap="wrap">
                      <Button size="small" onClick={() => startEdit(f)}>
                        {f.status === 'Active' ? 'View' : 'Build'}
                      </Button>
                      {f.status === 'Active' && (
                        <Button size="small" onClick={() => onCloneVersion(f)}>
                          New version
                        </Button>
                      )}
                      {f.status !== 'Active' && (
                        <Button
                          size="small"
                          color="error"
                          onClick={() => setDeleteFormTarget(f)}
                        >
                          Delete
                        </Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </Paper>

      {!editing ? (
        <Alert severity="info">Select or create a form above to open the builder workspace.</Alert>
      ) : (
        <>
          {!canEdit && (
            <Alert severity="warning">
              This form is Active and locked. Use <strong>New version</strong> to edit a draft copy.
            </Alert>
          )}

          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: '1fr', xl: '260px 1fr 300px' },
              gap: 2,
              alignItems: 'stretch',
            }}
          >
            {/* 1. Field Templates */}
            <Paper elevation={0} sx={panelSx}>
              <Typography variant="subtitle2" fontWeight={700} mb={0.5}>
                1. Field Templates
              </Typography>
              <Typography variant="caption" color="text.secondary" mb={1.5} display="block">
                Click a template to add it to the form structure.
              </Typography>
              <Box sx={{ overflowY: 'auto', flex: 1, pr: 0.5 }}>
                {FIELD_TEMPLATE_GROUPS.map((group) => (
                  <Box key={group.title} mb={2}>
                    <Typography
                      variant="caption"
                      fontWeight={700}
                      color="text.secondary"
                      sx={{ textTransform: 'uppercase', letterSpacing: 0.6 }}
                    >
                      {group.title}
                    </Typography>
                    <Stack spacing={0.75} mt={0.75}>
                      {group.templates.map((t) => (
                        <Button
                          key={t.type}
                          size="small"
                          variant="outlined"
                          startIcon={<AddOutlinedIcon />}
                          disabled={!canEdit}
                          onClick={() => addTemplate(t)}
                          sx={{ justifyContent: 'flex-start', textTransform: 'none' }}
                        >
                          {t.name}
                        </Button>
                      ))}
                    </Stack>
                  </Box>
                ))}
              </Box>
            </Paper>

            {/* 2. Form Structure */}
            <Paper elevation={0} sx={panelSx}>
              <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1}>
                <Box>
                  <Typography variant="subtitle2" fontWeight={700}>
                    2. Form Structure
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {editing.name} · v{editing.version} · {fields.length} field(s)
                  </Typography>
                </Box>
                <Stack direction="row" spacing={0.5}>
                  <Button
                    size="small"
                    disabled={!canEdit || selectedIndex == null || selectedIndex === 0}
                    onClick={() => moveSelected(-1)}
                  >
                    <KeyboardArrowUpIcon fontSize="small" />
                  </Button>
                  <Button
                    size="small"
                    disabled={
                      !canEdit || selectedIndex == null || selectedIndex === fields.length - 1
                    }
                    onClick={() => moveSelected(1)}
                  >
                    <KeyboardArrowDownIcon fontSize="small" />
                  </Button>
                  <Tooltip title="Duplicate selected field">
                    <span>
                      <Button
                        size="small"
                        disabled={!canEdit || selectedIndex == null}
                        onClick={() => selectedIndex != null && duplicateFieldAt(selectedIndex)}
                        aria-label="Duplicate selected field"
                      >
                        <ContentCopyOutlinedIcon fontSize="small" />
                      </Button>
                    </span>
                  </Tooltip>
                  <Button
                    size="small"
                    color="error"
                    disabled={!canEdit || selectedIndex == null}
                    onClick={() => setDeleteOpen(true)}
                  >
                    <DeleteOutlineOutlinedIcon fontSize="small" />
                  </Button>
                  {canEdit && (
                    <Button size="small" variant="contained" onClick={onSaveFields} disabled={saving}>
                      Save
                    </Button>
                  )}
                </Stack>
              </Stack>

              {fields.length === 0 ? (
                <Alert severity="info" sx={{ mt: 1 }}>
                  No fields yet. Add a field from the templates on the left.
                </Alert>
              ) : (
                <>
                  {canEdit && (
                    <Typography variant="caption" color="text.secondary" mb={1} display="block">
                      Drag the grip handle to reorder fields
                    </Typography>
                  )}
                  <List dense sx={{ overflowY: 'auto', flex: 1 }}>
                    {fields.map((field, index) => {
                      const isDragging = dragFromIndex === index;
                      const isDropTarget = dragOverIndex === index && dragFromIndex !== index;
                      return (
                        <ListItemButton
                          key={`${field.id}-${index}`}
                          selected={selectedIndex === index}
                          onClick={() => setSelectedIndex(index)}
                          draggable={canEdit}
                          onDragStart={(e) => {
                            onDragStart(index);
                            e.dataTransfer.effectAllowed = 'move';
                            e.dataTransfer.setData('text/plain', String(index));
                          }}
                          onDragOver={(e) => onDragOver(e, index)}
                          onDragLeave={() => {
                            if (dragOverIndex === index) setDragOverIndex(null);
                          }}
                          onDrop={(e) => {
                            e.preventDefault();
                            onDrop(index);
                          }}
                          onDragEnd={onDragEnd}
                          sx={{
                            borderRadius: 1.5,
                            mb: 0.5,
                            border: 1,
                            borderColor: isDropTarget ? 'primary.main' : 'divider',
                            bgcolor: isDragging
                              ? 'action.hover'
                              : isDropTarget
                                ? 'action.selected'
                                : undefined,
                            opacity: isDragging ? 0.55 : 1,
                            cursor: canEdit ? 'grab' : 'pointer',
                            '&:active': { cursor: canEdit ? 'grabbing' : 'pointer' },
                            transition: 'border-color .12s ease, opacity .12s ease, background .12s ease',
                          }}
                        >
                          <ListItemIcon sx={{ minWidth: 36 }}>
                            <IconButton
                              size="small"
                              edge="start"
                              disabled={!canEdit}
                              disableRipple
                              sx={{
                                cursor: canEdit ? 'grab' : 'default',
                                color: 'text.secondary',
                                '&:active': { cursor: canEdit ? 'grabbing' : 'default' },
                              }}
                              aria-label={`Drag to reorder ${field.label || field.id}`}
                            >
                              <DragIndicatorIcon fontSize="small" />
                            </IconButton>
                          </ListItemIcon>
                          <ListItemText
                            primary={
                              <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                                <Typography fontWeight={600} noWrap>
                                  {field.label || '(untitled)'}
                                </Typography>
                                {field.required && !isLayoutField(field.type) && (
                                  <Chip size="small" label="Required" color="error" />
                                )}
                                <Chip
                                  size="small"
                                  label={field.type}
                                  color="primary"
                                  variant="outlined"
                                />
                                {resolveColumnSpan(field) !== 12 && (
                                  <Chip
                                    size="small"
                                    label={
                                      COLUMN_SPAN_OPTIONS.find(
                                        (o) => o.value === resolveColumnSpan(field),
                                      )?.short ?? `${resolveColumnSpan(field)}/12`
                                    }
                                    variant="outlined"
                                  />
                                )}
                              </Stack>
                            }
                            secondary={`Position: ${index + 1} · ${field.id}`}
                          />
                          {canEdit && (
                            <Tooltip title="Duplicate field">
                              <IconButton
                                size="small"
                                edge="end"
                                aria-label={`Duplicate ${field.label || field.id}`}
                                onClick={(e) => {
                                  e.stopPropagation();
                                  duplicateFieldAt(index);
                                }}
                                onMouseDown={(e) => e.stopPropagation()}
                              >
                                <ContentCopyOutlinedIcon fontSize="small" />
                              </IconButton>
                            </Tooltip>
                          )}
                        </ListItemButton>
                      );
                    })}
                  </List>
                </>
              )}
            </Paper>

            {/* 3. Field Properties (+ optional preview above on xl) */}
            <Stack spacing={2}>
              {showPreview && (
                <Paper elevation={0} sx={{ ...panelSx, minHeight: 220, maxHeight: 320 }}>
                  <Typography variant="subtitle2" fontWeight={700} mb={1}>
                    Live Preview
                  </Typography>
                  <Box sx={{ overflowY: 'auto', flex: 1 }}>
                    {fields.length === 0 ? (
                      <Alert severity="info">Add fields to preview.</Alert>
                    ) : (
                      <DynamicFormFields
                        fields={fields}
                        values={previewValues}
                        onChange={(id, value) =>
                          setPreviewValues((prev) => ({ ...prev, [id]: value }))
                        }
                      />
                    )}
                  </Box>
                </Paper>
              )}

              <Paper elevation={0} sx={panelSx}>
                <Typography variant="subtitle2" fontWeight={700} mb={0.5}>
                  3. Field Properties
                </Typography>
                <Typography variant="caption" color="text.secondary" mb={1.5} display="block">
                  {selected ? 'Edit the selected field' : 'Select a field to edit its properties'}
                </Typography>

                {!selected ? (
                  <Alert severity="info">Select a field in Form Structure.</Alert>
                ) : (
                  <FieldPropertiesEditor
                    field={selected}
                    allFields={fields}
                    canEdit={canEdit}
                    onPatch={patchSelected}
                    onPatchValidation={patchValidation}
                  />
                )}
              </Paper>
            </Stack>
          </Box>

          {showPreview && (
            <Paper
              elevation={0}
              sx={{
                p: 2.5,
                border: 1,
                borderColor: 'divider',
                borderRadius: 2,
                display: { xs: 'block', xl: 'none' },
              }}
            >
              <Stack direction="row" justifyContent="space-between" alignItems="center" mb={2}>
                <Typography variant="subtitle2" fontWeight={700}>
                  Live Preview
                </Typography>
                <Chip size="small" label={`${tab} applicant view`} />
              </Stack>
              <Divider sx={{ mb: 2 }} />
              {fields.length === 0 ? (
                <Alert severity="info">Add fields to preview the form.</Alert>
              ) : (
                <Box sx={{ maxWidth: 640 }}>
                  <DynamicFormFields
                    fields={fields}
                    values={previewValues}
                    onChange={(id, value) =>
                      setPreviewValues((prev) => ({ ...prev, [id]: value }))
                    }
                  />
                </Box>
              )}
            </Paper>
          )}
        </>
      )}

      <Dialog open={deleteOpen} onClose={() => setDeleteOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Delete Field</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Are you sure you want to delete &quot;{selected?.label}&quot;? This cannot be undone
            until you save.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteOpen(false)}>Cancel</Button>
          <Button color="error" variant="contained" onClick={confirmDeleteField}>
            Delete Field
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={Boolean(deleteFormTarget)}
        onClose={() => setDeleteFormTarget(null)}
        maxWidth="xs"
        fullWidth
      >
        <DialogTitle>Delete Form Version</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Delete &quot;{deleteFormTarget?.name}&quot; v{deleteFormTarget?.version}? This cannot be
            undone.
          </Typography>
          {deleteFormTarget?.status === 'Published' && (
            <Alert severity="warning" sx={{ mt: 1.5 }}>
              This version is Published. Deleting removes it from the list permanently.
            </Alert>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteFormTarget(null)} disabled={deleting}>
            Cancel
          </Button>
          <Button
            color="error"
            variant="contained"
            onClick={onDeleteFormVersion}
            disabled={deleting}
          >
            {deleting ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

function optionsRecordToList(options?: Record<string, string>): string[] {
  if (!options || Object.keys(options).length === 0) return ['Option 1', 'Option 2'];
  return Object.values(options);
}

function listToOptionsRecord(labels: string[]): Record<string, string> {
  const out: Record<string, string> = {};
  labels.forEach((label, i) => {
    const trimmed = label.trim();
    if (!trimmed) return;
    out[`option_${i + 1}`] = trimmed;
  });
  return out;
}

function ChoiceOptionsEditor({
  fieldId,
  options,
  canEdit,
  onChange,
}: {
  fieldId: string;
  options?: Record<string, string>;
  canEdit: boolean;
  onChange: (options: Record<string, string>) => void;
}) {
  const [rows, setRows] = useState<string[]>(() => optionsRecordToList(options));

  // Reset only when switching to a different field (avoid fighting keystrokes)
  useEffect(() => {
    setRows(optionsRecordToList(options));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fieldId]);

  const commit = (next: string[]) => {
    setRows(next);
    onChange(listToOptionsRecord(next));
  };

  return (
    <Box>
      <Typography variant="body2" fontWeight={600} mb={1}>
        Options{' '}
        <Typography component="span" color="error.main">
          *
        </Typography>
      </Typography>
      <Stack spacing={1}>
        {rows.map((row, index) => (
          <Stack key={`${fieldId}-opt-${index}`} direction="row" spacing={1} alignItems="center">
            <TextField
              size="small"
              fullWidth
              disabled={!canEdit}
              value={row}
              placeholder={`Option ${index + 1}`}
              onChange={(e) => {
                const next = [...rows];
                next[index] = e.target.value;
                commit(next);
              }}
            />
            <IconButton
              size="small"
              color="error"
              disabled={!canEdit || rows.length <= 1}
              onClick={() => commit(rows.filter((_, i) => i !== index))}
              aria-label={`Remove option ${index + 1}`}
            >
              <DeleteOutlineOutlinedIcon fontSize="small" />
            </IconButton>
          </Stack>
        ))}
      </Stack>
      <Button
        size="small"
        startIcon={<AddOutlinedIcon />}
        disabled={!canEdit}
        onClick={() => commit([...rows, `Option ${rows.length + 1}`])}
        sx={{ mt: 1, textTransform: 'none' }}
      >
        Add Option
      </Button>
    </Box>
  );
}

function FieldPropertiesEditor({
  field,
  allFields,
  canEdit,
  onPatch,
  onPatchValidation,
}: {
  field: SasFormField;
  allFields: SasFormField[];
  canEdit: boolean;
  onPatch: (patch: Partial<SasFormField>) => void;
  onPatchValidation: (patch: Partial<SasFieldValidation>) => void;
}) {
  const val = field.validation ?? {};
  const layout = isLayoutField(field.type);
  const lengthMode =
    val.lengthMode ||
    (val.minLength && val.maxLength && val.minLength === val.maxLength
      ? 'exact'
      : val.minLength && val.maxLength
        ? 'range'
        : val.maxLength
          ? 'max'
          : 'none');
  const restriction = val.inputRestriction || 'none';
  const otherFields = allFields.filter((f) => f.id !== field.id && !isLayoutField(f.type));

  const setLengthMode = (mode: string) => {
    const next: SasFieldValidation = { ...val, lengthMode: mode };
    if (mode === 'none') {
      delete next.minLength;
      delete next.maxLength;
      delete next.lengthMode;
    } else if (mode === 'exact') {
      const n = val.maxLength ?? val.minLength ?? 11;
      next.minLength = n;
      next.maxLength = n;
    } else if (mode === 'max') {
      delete next.minLength;
      next.maxLength = val.maxLength ?? 255;
    } else if (mode === 'range') {
      next.minLength = val.minLength ?? 1;
      next.maxLength = val.maxLength ?? 255;
    }
    onPatchValidation(next);
  };

  return (
    <Stack
      spacing={2}
      sx={{
        overflowY: 'auto',
        flex: 1,
        pr: 0.5,
        // Outlined TextField labels sit above the border — without top padding they clip
        pt: 1,
        pb: 0.5,
      }}
    >
      <TextField
        label={
          field.type === 'section_heading'
            ? 'Section Title'
            : field.type === 'divider'
              ? 'Center Label (optional)'
              : 'Field Label'
        }
        size="small"
        fullWidth
        required={!layout || field.type === 'section_heading'}
        disabled={!canEdit}
        value={field.label}
        onChange={(e) => onPatch({ label: e.target.value })}
        InputLabelProps={{ shrink: true }}
      />
      <TextField
        label="Field ID"
        size="small"
        fullWidth
        disabled={!canEdit}
        value={field.id}
        onChange={(e) => onPatch({ id: slugifyFieldId(e.target.value) })}
        helperText="Submission data key"
        InputLabelProps={{ shrink: true }}
      />
      <TextField
        select
        label="Field Type"
        size="small"
        fullWidth
        disabled={!canEdit}
        value={field.type}
        onChange={(e) => onPatch({ type: e.target.value })}
        InputLabelProps={{ shrink: true }}
      >
        {SAS_FIELD_TYPES.map((t) => (
          <MenuItem key={t.value} value={t.value}>
            {t.label}
          </MenuItem>
        ))}
      </TextField>

      {!layout && (
        <TextField
          select
          label="Column width"
          size="small"
          fullWidth
          disabled={!canEdit}
          value={resolveColumnSpan(field)}
          onChange={(e) => onPatch({ columnSpan: Number(e.target.value) })}
          helperText="Place consecutive half/third/quarter fields next to each other in one row (desktop). Always full width on mobile."
          InputLabelProps={{ shrink: true }}
        >
          {COLUMN_SPAN_OPTIONS.map((opt) => (
            <MenuItem key={opt.value} value={opt.value}>
              {opt.label}
            </MenuItem>
          ))}
        </TextField>
      )}

      {!layout && (
        <FormControlLabel
          control={
            <Checkbox
              checked={field.required}
              disabled={!canEdit}
              onChange={(_, checked) => onPatch({ required: checked })}
            />
          }
          label="Required field"
        />
      )}

      {/* Type-specific options */}
      {['text', 'textarea', 'email', 'phone', 'url'].includes(field.type) && (
        <>
          <TextField
            label="Placeholder Text"
            size="small"
            fullWidth
            disabled={!canEdit}
            value={field.placeholder ?? ''}
            onChange={(e) => onPatch({ placeholder: e.target.value })}
          />
          <TextField
            select
            label="Allowed characters"
            size="small"
            fullWidth
            disabled={!canEdit}
            value={restriction}
            onChange={(e) =>
              onPatchValidation(applyInputRestriction({ ...val }, e.target.value))
            }
            helperText="Applicants cannot type disallowed characters"
          >
            <MenuItem value="none">Any text</MenuItem>
            <MenuItem value="numeric">Numbers only</MenuItem>
            <MenuItem value="alpha">Letters only</MenuItem>
            <MenuItem value="alphanumeric">Letters and numbers only</MenuItem>
          </TextField>
          <Box>
            <Typography variant="caption" fontWeight={600} display="block" mb={1}>
              Character Length
            </Typography>
            <RadioGroup value={lengthMode} onChange={(e) => setLengthMode(e.target.value)}>
              <FormControlLabel
                disabled={!canEdit}
                value="exact"
                control={<Radio size="small" />}
                label={
                  <Stack direction="row" spacing={1} alignItems="center">
                    <span>Exactly</span>
                    <TextField
                      size="small"
                      type="number"
                      disabled={!canEdit || lengthMode !== 'exact'}
                      value={lengthMode === 'exact' ? (val.maxLength ?? '') : ''}
                      onChange={(e) => {
                        const n = Number(e.target.value) || undefined;
                        onPatchValidation({ lengthMode: 'exact', minLength: n, maxLength: n });
                      }}
                      sx={{ width: 80 }}
                    />
                    <Typography variant="caption">characters</Typography>
                  </Stack>
                }
              />
              <FormControlLabel
                disabled={!canEdit}
                value="max"
                control={<Radio size="small" />}
                label={
                  <Stack direction="row" spacing={1} alignItems="center">
                    <span>Up to</span>
                    <TextField
                      size="small"
                      type="number"
                      disabled={!canEdit || lengthMode !== 'max'}
                      value={lengthMode === 'max' ? (val.maxLength ?? '') : ''}
                      onChange={(e) =>
                        onPatchValidation({
                          lengthMode: 'max',
                          maxLength: Number(e.target.value) || undefined,
                          minLength: undefined,
                        })
                      }
                      sx={{ width: 80 }}
                    />
                    <Typography variant="caption">characters</Typography>
                  </Stack>
                }
              />
              <FormControlLabel
                disabled={!canEdit}
                value="range"
                control={<Radio size="small" />}
                label={
                  <Stack direction="row" spacing={1} alignItems="center">
                    <span>Between</span>
                    <TextField
                      size="small"
                      type="number"
                      disabled={!canEdit || lengthMode !== 'range'}
                      value={lengthMode === 'range' ? (val.minLength ?? '') : ''}
                      onChange={(e) =>
                        onPatchValidation({
                          lengthMode: 'range',
                          minLength: Number(e.target.value) || undefined,
                        })
                      }
                      sx={{ width: 70 }}
                    />
                    <span>and</span>
                    <TextField
                      size="small"
                      type="number"
                      disabled={!canEdit || lengthMode !== 'range'}
                      value={lengthMode === 'range' ? (val.maxLength ?? '') : ''}
                      onChange={(e) =>
                        onPatchValidation({
                          lengthMode: 'range',
                          maxLength: Number(e.target.value) || undefined,
                        })
                      }
                      sx={{ width: 70 }}
                    />
                  </Stack>
                }
              />
              <FormControlLabel
                disabled={!canEdit}
                value="none"
                control={<Radio size="small" />}
                label="No length limit"
              />
            </RadioGroup>
          </Box>
        </>
      )}

      {(field.type === 'number' || field.type === 'currency') && (
        <Stack direction="row" spacing={1}>
          <TextField
            label={field.type === 'currency' ? 'Minimum Amount' : 'Minimum Value'}
            size="small"
            type="number"
            fullWidth
            disabled={!canEdit}
            value={val.min ?? ''}
            onChange={(e) =>
              onPatchValidation({
                min: e.target.value === '' ? undefined : Number(e.target.value),
              })
            }
          />
          <TextField
            label={field.type === 'currency' ? 'Maximum Amount' : 'Maximum Value'}
            size="small"
            type="number"
            fullWidth
            disabled={!canEdit}
            value={val.max ?? ''}
            onChange={(e) =>
              onPatchValidation({
                max: e.target.value === '' ? undefined : Number(e.target.value),
              })
            }
          />
        </Stack>
      )}

      {isChoiceType(field.type) && (
        <ChoiceOptionsEditor
          fieldId={field.id}
          options={field.options ?? val.options}
          canEdit={canEdit}
          onChange={(options) => onPatch({ options, validation: { ...val, options } })}
        />
      )}

      {isFileType(field.type) && (
        <>
          <TextField
            label="Accepted File Types"
            size="small"
            fullWidth
            disabled={!canEdit}
            value={(val.allowedTypes ?? []).join(',')}
            onChange={(e) =>
              onPatchValidation({
                allowedTypes: e.target.value
                  .split(',')
                  .map((s) => s.trim().toLowerCase())
                  .filter(Boolean),
              })
            }
            placeholder="pdf,jpg,png"
          />
          <TextField
            label="Maximum File Size (MB)"
            size="small"
            type="number"
            fullWidth
            disabled={!canEdit}
            value={val.maxSize ? Math.round(val.maxSize / (1024 * 1024)) : ''}
            onChange={(e) =>
              onPatchValidation({
                maxSize: e.target.value
                  ? Number(e.target.value) * 1024 * 1024
                  : undefined,
              })
            }
          />
          {field.type === 'multi_file' && (
            <TextField
              label="Maximum Files"
              size="small"
              type="number"
              fullWidth
              disabled={!canEdit}
              value={val.maxFiles ?? 5}
              onChange={(e) =>
                onPatchValidation({ maxFiles: Number(e.target.value) || undefined })
              }
            />
          )}
          <FormControlLabel
            control={
              <Checkbox
                checked={val.preview !== false}
                disabled={!canEdit}
                onChange={(_, checked) => onPatchValidation({ preview: checked })}
              />
            }
            label="Enable preview before submit"
          />
        </>
      )}

      {field.type === 'terms' && (
        <TextField
          label="Declaration Text"
          size="small"
          fullWidth
          multiline
          minRows={4}
          disabled={!canEdit}
          value={
            val.declaration ||
            'I certify that the information provided is true and correct, and I agree to the terms and conditions of this accreditation application.'
          }
          onChange={(e) => onPatchValidation({ declaration: e.target.value })}
        />
      )}

      {field.type === 'section_heading' && (
        <TextField
          label="Subtitle (optional)"
          size="small"
          fullWidth
          disabled={!canEdit}
          value={val.subtitle ?? ''}
          onChange={(e) => onPatchValidation({ subtitle: e.target.value })}
          helperText="Visual section break — does not collect data"
        />
      )}

      {field.type === 'divider' && (
        <Alert severity="info">
          Horizontal separator. Optional center label uses the title field above.
        </Alert>
      )}

      {field.type === 'address' && (
        <Alert severity="info">
          Region, Province, City/Municipality, and Barangay use the location database (full picker
          coming later).
        </Alert>
      )}

      {field.type === 'geolocation' && (
        <>
          <Alert severity="info">
            Applicants pick business location on a map. Lat/lng are saved automatically.
          </Alert>
          <TextField
            label="Default Latitude"
            size="small"
            type="number"
            fullWidth
            disabled={!canEdit}
            value={val.defaultLat ?? 14.5995}
            onChange={(e) => onPatchValidation({ defaultLat: Number(e.target.value) })}
          />
          <TextField
            label="Default Longitude"
            size="small"
            type="number"
            fullWidth
            disabled={!canEdit}
            value={val.defaultLng ?? 120.9842}
            onChange={(e) => onPatchValidation({ defaultLng: Number(e.target.value) })}
          />
          <TextField
            label="Default Map Zoom"
            size="small"
            type="number"
            fullWidth
            disabled={!canEdit}
            value={val.defaultZoom ?? 13}
            onChange={(e) => onPatchValidation({ defaultZoom: Number(e.target.value) })}
            inputProps={{ min: 1, max: 18 }}
          />
        </>
      )}

      {!layout && (
        <>
          <Divider />
          <Typography
            variant="caption"
            fontWeight={700}
            color="text.secondary"
            sx={{ textTransform: 'uppercase', letterSpacing: 0.5 }}
          >
            Dynamic rules
          </Typography>
          <TextField
            select
            label="Show only when"
            size="small"
            fullWidth
            disabled={!canEdit}
            value={val.showWhen?.field ?? ''}
            onChange={(e) => {
              const fieldId = e.target.value;
              if (!fieldId) {
                const next = { ...val };
                delete next.showWhen;
                onPatch({ validation: next });
                return;
              }
              onPatchValidation({
                showWhen: {
                  field: fieldId,
                  operator: 'equals',
                  value: val.showWhen?.value ?? '',
                },
              });
            }}
          >
            <MenuItem value="">Always visible</MenuItem>
            {otherFields.map((f) => (
              <MenuItem key={f.id} value={f.id}>
                {f.label}
              </MenuItem>
            ))}
          </TextField>
          {val.showWhen?.field && (
            <TextField
              label="Value equals..."
              size="small"
              fullWidth
              disabled={!canEdit}
              value={val.showWhen.value ?? ''}
              onChange={(e) =>
                onPatchValidation({
                  showWhen: {
                    field: val.showWhen!.field,
                    operator: 'equals',
                    value: e.target.value,
                  },
                })
              }
              helperText="Example: Company Type = Corporation"
            />
          )}
          <FormControlLabel
            control={
              <Checkbox
                checked={Boolean(val.unique)}
                disabled={!canEdit}
                onChange={(_, checked) => {
                  if (checked) onPatchValidation({ unique: true });
                  else {
                    const next = { ...val };
                    delete next.unique;
                    onPatch({ validation: next });
                  }
                }}
              />
            }
            label="Unique value validation (TIN, SEC, license)"
          />
        </>
      )}

      <TextField
        label="Help text"
        size="small"
        fullWidth
        multiline
        minRows={2}
        disabled={!canEdit}
        value={field.helpText ?? ''}
        onChange={(e) => onPatch({ helpText: e.target.value })}
      />
    </Stack>
  );
}
