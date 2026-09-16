/**
 * Settings → Access. Who can do what in Sales.
 *
 * This screen exists because the permission table did not have one. The checks
 * were being enforced on every query from the first build, but there was no way
 * to write a profile or hand one to anybody, so the only path to granting
 * access was an INSERT in psql — and nothing in the product said so.
 *
 * THE TWO HALVES ARE LABELLED, DELIBERATELY. People come from Manage; what they
 * may do comes from here. Saying that on the screen is not decoration: the
 * question "why isn't my colleague in this list" has one answer (invite them in
 * Manage) and the question "why can't they see margins" has another (tick the
 * box here), and somebody who cannot tell the two apart tries the wrong fix.
 *
 * The owner is shown and not editable. Ownership is Manage's answer, and the
 * owner holds the whole catalogue whatever this screen says.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { KeyRound, Plus, ShieldCheck, Trash2, UserCog } from 'lucide-react'
import { ApiError } from '../services/api'
import {
  accessApi,
  type AccessMember,
  type PermissionCatalog,
  type PermissionProfile,
} from '../services/access'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Badge, Button, DataState, Field, Input, Notice, Panel, Textarea } from '../ui'

function countPermissions(catalog: PermissionCatalog): number {
  return Object.values(catalog).reduce((total, group) => total + Object.keys(group).length, 0)
}

/** The label the catalogue gives a code, or the code when it is not in it. */
function labelFor(catalog: PermissionCatalog, code: string): string {
  for (const group of Object.values(catalog)) {
    if (group[code]) return group[code]
  }

  return code
}

export default function Access() {
  const { scope, can, session } = useSales()
  const mayManage = can('access.manage')

  const profilesState = useApi(
    (signal) => accessApi.profiles(signal),
    [scope?.cmp_id],
    Boolean(scope) && mayManage,
  )
  const membersState = useApi(
    (signal) => accessApi.members(signal),
    [scope?.cmp_id],
    Boolean(scope) && mayManage,
  )

  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState<PermissionProfile | 'new' | null>(null)

  const catalog = profilesState.data?.data.catalog ?? {}
  const profiles = profilesState.data?.data.profiles ?? []
  const members = membersState.data?.data.members ?? []
  const orphans = membersState.data?.data.orphans ?? []
  const directory = membersState.data?.data.directory

  const activeProfiles = useMemo(() => profiles.filter((p) => p.is_active), [profiles])

  const reloadAll = useCallback(() => {
    profilesState.reload()
    membersState.reload()
  }, [profilesState, membersState])

  // A message that stays on screen after the thing it described has been
  // superseded reads as the current state. Clear it on the next action.
  async function run(what: string, work: () => Promise<void>) {
    setBusy(true)
    setError(null)
    setSaved(null)
    try {
      await work()
      setSaved(what)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (!mayManage) {
    return (
      <div className="sales-stack" style={{ maxWidth: '48rem' }}>
        <header className="sales-page-header">
          <div>
            <h1>Access</h1>
            <p>Who can do what in Sales.</p>
          </div>
        </header>
        <Notice tone="info" title="You cannot change access">
          Managing Sales permission profiles needs the <strong>Manage Sales permission profiles</strong>{' '}
          right, which the company owner holds. Ask them to make the change, or to give you that
          profile.
        </Notice>
      </div>
    )
  }

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Access</h1>
          <p>
            Manage decides who can open this company. Sales decides what they can do once they are
            in — that is what this page sets.
          </p>
        </div>
      </header>

      {error && (
        <Notice tone="danger" title="That did not go through">
          {error}
        </Notice>
      )}
      {saved && <Notice tone="success">{saved}</Notice>}

      {/* ------------------------------------------------------------------ */}
      {/* People                                                              */}
      {/* ------------------------------------------------------------------ */}

      <Panel
        title="People in this company"
        description="Read live from Manage. To add or remove somebody, do it in Manage — this list follows."
      >
        {directory?.status === 'unavailable' && (
          <Notice tone="warning" title="The list of people could not be loaded">
            {directory.reason ?? 'Manage could not be reached.'} The profiles below are still
            correct; only the names are missing.
            <div style={{ marginTop: 10 }}>
              <Button small onClick={() => membersState.reload()}>
                Retry
              </Button>
            </div>
          </Notice>
        )}

        <DataState
          status={
            membersState.loading
              ? 'loading'
              : membersState.error
                ? 'error'
                : members.length === 0 && directory?.status === 'ready'
                  ? 'empty'
                  : 'ready'
          }
          message={membersState.error ?? undefined}
          retry={membersState.reload}
        >
          <div className="sales-stack-tight">
            {members.map((member) => (
              <MemberRow
                key={member.uuid}
                member={member}
                profiles={activeProfiles}
                catalog={catalog}
                isSelf={member.uuid === session?.uuid}
                busy={busy}
                onSave={(ids) =>
                  run(
                    `Saved ${member.display_name ?? 'that person'}'s Sales access.`,
                    async () => {
                      await accessApi.setMemberProfiles(member.uuid, ids)
                      reloadAll()
                    },
                  )
                }
              />
            ))}
          </div>
        </DataState>
      </Panel>

      {orphans.length > 0 && (
        <Panel
          title="Assignments with no matching person"
          description="These profiles are held by users Manage no longer lists for this company. They grant nothing — signing in would be refused first — but they are worth clearing."
        >
          <div className="sales-stack-tight">
            {orphans.map((orphan) => (
              <div key={orphan.uuid} className="sales-row-between" style={{ gap: 12, flexWrap: 'wrap' }}>
                <div style={{ minWidth: 0 }}>
                  <code style={{ fontSize: '0.8rem' }}>{orphan.uuid}</code>
                  <div className="sales-cell-sub">
                    {orphan.profiles.map((p) => p.profile_name).join(', ')}
                  </div>
                </div>
                <Button
                  tone="danger"
                  small
                  disabled={busy}
                  onClick={() =>
                    run('Cleared that assignment.', async () => {
                      await accessApi.clearMember(orphan.uuid)
                      reloadAll()
                    })
                  }
                >
                  <Trash2 size={13} aria-hidden /> Clear
                </Button>
              </div>
            ))}
          </div>
        </Panel>
      )}

      {/* ------------------------------------------------------------------ */}
      {/* Profiles                                                            */}
      {/* ------------------------------------------------------------------ */}

      <Panel
        title="Permission profiles"
        description={`A profile is a named set of Sales permissions. There are ${countPermissions(catalog)} to choose from.`}
        action={
          <Button small onClick={() => setEditing('new')} disabled={busy}>
            <Plus size={14} aria-hidden /> New profile
          </Button>
        }
      >
        <DataState
          status={
            profilesState.loading
              ? 'loading'
              : profilesState.error
                ? 'error'
                : profiles.length === 0
                  ? 'empty'
                  : 'ready'
          }
          message={
            profilesState.error ??
            (profiles.length === 0
              ? 'No profiles yet. The company owner already holds everything; create a profile for everybody else.'
              : undefined)
          }
          retry={profilesState.reload}
        >
          <div className="sales-stack-tight">
            {profiles.map((profile) => (
              <div key={profile.profile_id} className="sales-row-between" style={{ gap: 12, flexWrap: 'wrap' }}>
                <div style={{ minWidth: 0 }}>
                  <div className="sales-row" style={{ gap: 8 }}>
                    <KeyRound size={15} aria-hidden style={{ color: 'var(--accent)' }} />
                    <strong>{profile.profile_name}</strong>
                    {!profile.is_active && <Badge tone="warning">Inactive</Badge>}
                    {profile.is_system && <Badge tone="info">Built in</Badge>}
                  </div>
                  <div className="sales-cell-sub">
                    {profile.permissions.length} permission{profile.permissions.length === 1 ? '' : 's'} ·{' '}
                    {profile.holders} {profile.holders === 1 ? 'person holds it' : 'people hold it'}
                    {profile.description ? ` · ${profile.description}` : ''}
                  </div>
                </div>
                <div className="sales-row" style={{ gap: 8 }}>
                  <Button tone="ghost" small onClick={() => setEditing(profile)} disabled={busy}>
                    <UserCog size={13} aria-hidden /> Edit
                  </Button>
                  {!profile.is_system && (
                    <Button
                      tone="danger"
                      small
                      disabled={busy}
                      onClick={() =>
                        run(`Deleted "${profile.profile_name}".`, async () => {
                          try {
                            await accessApi.deleteProfile(profile.profile_id)
                          } catch (err) {
                            // The API refuses a profile somebody holds until the
                            // caller has been told how many people lose access.
                            if (
                              err instanceof ApiError &&
                              err.status === 409 &&
                              window.confirm(`${err.message}\n\nDelete it anyway?`)
                            ) {
                              await accessApi.deleteProfile(profile.profile_id, true)
                            } else {
                              throw err
                            }
                          }
                          reloadAll()
                        })
                      }
                    >
                      <Trash2 size={13} aria-hidden /> Delete
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </DataState>
      </Panel>

      {editing !== null && (
        <ProfileEditor
          catalog={catalog}
          profile={editing === 'new' ? null : editing}
          busy={busy}
          onClose={() => setEditing(null)}
          onSave={(input) =>
            run(editing === 'new' ? `Created "${input.profile_name}".` : `Saved "${input.profile_name}".`, async () => {
              if (editing === 'new') {
                await accessApi.createProfile(input)
              } else {
                await accessApi.updateProfile(editing.profile_id, input)
              }
              setEditing(null)
              reloadAll()
            })
          }
        />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------

/**
 * One person, and the profiles they hold.
 *
 * The tick boxes are local until Save, so a slow network cannot leave half a
 * change applied — the request sends the whole set or none of it.
 */
function MemberRow({
  member,
  profiles,
  catalog,
  isSelf,
  busy,
  onSave,
}: {
  member: AccessMember
  profiles: PermissionProfile[]
  catalog: PermissionCatalog
  isSelf: boolean
  busy: boolean
  onSave: (profileIds: number[]) => void
}) {
  const held = useMemo(() => member.profiles.map((p) => p.profile_id), [member.profiles])
  const [selected, setSelected] = useState<number[]>(held)
  const [open, setOpen] = useState(false)

  // A reload brings new server state; the local ticks must follow it rather
  // than keep showing what was on screen before the save.
  useEffect(() => setSelected(held), [held])

  const dirty = useMemo(
    () => held.length !== selected.length || held.some((id) => !selected.includes(id)),
    [held, selected],
  )

  return (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: 12 }}>
      <div className="sales-row-between" style={{ gap: 12, flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0 }}>
          <div className="sales-row" style={{ gap: 8 }}>
            <strong>{member.display_name ?? 'Unnamed user'}</strong>
            {member.is_owner && (
              <Badge tone="success" dot>
                Company owner
              </Badge>
            )}
            {isSelf && <Badge tone="info">You</Badge>}
          </div>
          <div className="sales-cell-sub">
            {member.email ?? member.uuid}
            {member.is_owner
              ? ' · holds every Sales permission'
              : member.profiles.length === 0
                ? ' · no Sales access yet'
                : ` · ${member.profiles.map((p) => p.profile_name).join(', ')}`}
          </div>
        </div>

        {member.is_owner ? (
          <span className="sales-muted sales-row" style={{ gap: 6, fontSize: '0.82rem' }}>
            <ShieldCheck size={14} aria-hidden /> Set in Manage
          </span>
        ) : (
          <Button tone="ghost" small onClick={() => setOpen((o) => !o)} aria-expanded={open}>
            {open ? 'Close' : 'Change access'}
          </Button>
        )}
      </div>

      {open && !member.is_owner && (
        <div style={{ marginTop: 12, paddingLeft: 4 }}>
          {profiles.length === 0 ? (
            <p className="sales-muted" style={{ margin: 0 }}>
              There are no active profiles to give. Create one below first.
            </p>
          ) : (
            <>
              <div style={{ display: 'grid', gap: 6 }}>
                {profiles.map((profile) => (
                  <label key={profile.profile_id} className="sales-row" style={{ gap: 8, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={selected.includes(profile.profile_id)}
                      disabled={busy}
                      onChange={(e) =>
                        setSelected((current) =>
                          e.target.checked
                            ? [...current, profile.profile_id]
                            : current.filter((id) => id !== profile.profile_id),
                        )
                      }
                    />
                    <span>
                      {profile.profile_name}
                      <span className="sales-cell-sub">
                        {' '}
                        — {profile.permissions.length} permission
                        {profile.permissions.length === 1 ? '' : 's'}
                      </span>
                    </span>
                  </label>
                ))}
              </div>

              {member.effective.length > 0 && (
                <details style={{ marginTop: 10 }}>
                  <summary className="sales-muted" style={{ cursor: 'pointer', fontSize: '0.82rem' }}>
                    What they can do today ({member.effective.length})
                  </summary>
                  <ul className="sales-cell-sub" style={{ margin: '6px 0 0', paddingLeft: '1.1rem' }}>
                    {member.effective.map((code) => (
                      <li key={code}>{labelFor(catalog, code)}</li>
                    ))}
                  </ul>
                </details>
              )}

              <div className="sales-row" style={{ gap: 8, marginTop: 12 }}>
                <Button small disabled={busy || !dirty} onClick={() => onSave(selected)}>
                  Save access
                </Button>
                {dirty && (
                  <Button tone="ghost" small disabled={busy} onClick={() => setSelected(held)}>
                    Discard
                  </Button>
                )}
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}

/** Create or edit a profile: a name and the boxes it ticks. */
function ProfileEditor({
  catalog,
  profile,
  busy,
  onClose,
  onSave,
}: {
  catalog: PermissionCatalog
  profile: PermissionProfile | null
  busy: boolean
  onClose: () => void
  onSave: (input: { profile_name: string; description: string | null; permissions: string[] }) => void
}) {
  const [name, setName] = useState(profile?.profile_name ?? '')
  const [description, setDescription] = useState(profile?.description ?? '')
  const [selected, setSelected] = useState<string[]>(profile?.permissions ?? [])

  const locked = profile?.is_system ?? false

  function toggle(code: string, on: boolean) {
    setSelected((current) => (on ? [...current, code] : current.filter((c) => c !== code)))
  }

  function toggleGroup(codes: string[], on: boolean) {
    setSelected((current) =>
      on ? [...new Set([...current, ...codes])] : current.filter((c) => !codes.includes(c)),
    )
  }

  return (
    <Panel
      title={profile ? `Edit “${profile.profile_name}”` : 'New permission profile'}
      description={
        locked
          ? 'A built-in profile can be activated or deactivated, but its name and permissions are fixed so the same name means the same thing in every company.'
          : 'Name it after the job, not the person — “Sales executive”, “Branch manager”, “Read only”.'
      }
      action={
        <Button tone="ghost" small onClick={onClose} disabled={busy}>
          Cancel
        </Button>
      }
    >
      <div className="sales-form-grid">
        <Field label="Profile name" required>
          <Input
            value={name}
            disabled={busy || locked}
            maxLength={80}
            onChange={(e) => setName(e.target.value)}
            placeholder="Sales executive"
          />
        </Field>
        <Field label="Description" hint="Optional. What this profile is for.">
          <Textarea
            value={description}
            disabled={busy}
            maxLength={240}
            rows={2}
            onChange={(e) => setDescription(e.target.value)}
          />
        </Field>
      </div>

      <div className="sales-stack-tight" style={{ marginTop: 16 }}>
        {Object.entries(catalog).map(([group, permissions]) => {
          const codes = Object.keys(permissions)
          const allOn = codes.every((code) => selected.includes(code))

          return (
            <fieldset key={group} className="sales-form-section" style={{ border: 0, padding: 0, margin: 0 }}>
              <div className="sales-row-between" style={{ gap: 10 }}>
                <legend style={{ fontWeight: 650, padding: 0 }}>{group}</legend>
                {!locked && (
                  <Button tone="ghost" small disabled={busy} onClick={() => toggleGroup(codes, !allOn)}>
                    {allOn ? 'Clear group' : 'Select group'}
                  </Button>
                )}
              </div>
              <div style={{ display: 'grid', gap: 6, marginTop: 6 }}>
                {Object.entries(permissions).map(([code, label]) => (
                  <label key={code} className="sales-row" style={{ gap: 8, cursor: locked ? 'default' : 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={selected.includes(code)}
                      disabled={busy || locked}
                      onChange={(e) => toggle(code, e.target.checked)}
                    />
                    <span>
                      {label}
                      <code className="sales-cell-sub"> {code}</code>
                    </span>
                  </label>
                ))}
              </div>
            </fieldset>
          )
        })}
      </div>

      <div className="sales-row" style={{ gap: 8, marginTop: 16 }}>
        <Button
          disabled={busy || name.trim() === '' || (!locked && selected.length === 0)}
          onClick={() =>
            onSave({
              profile_name: name.trim(),
              description: description.trim() === '' ? null : description.trim(),
              permissions: selected,
            })
          }
        >
          {profile ? 'Save profile' : 'Create profile'}
        </Button>
        {!locked && selected.length === 0 && (
          <span className="sales-muted" style={{ fontSize: '0.82rem' }}>
            A profile that grants nothing is the same as no profile — tick at least one.
          </span>
        )}
      </div>
    </Panel>
  )
}
