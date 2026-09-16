/**
 * Sales access administration.
 *
 * Two resources, and the split matters: a PROFILE is a named set of Sales
 * permission codes and belongs to this product; a MEMBER is a person and
 * belongs to Manage. The member list is read live on every load and nothing
 * from it is kept, so a name changed in Manage is right here without Sales
 * being told anything.
 */

import { api } from './api'

/** Groups of permissions, as the backend's Permissions::CATALOG defines them. */
export type PermissionCatalog = Record<string, Record<string, string>>

export interface PermissionProfile {
  profile_id: number
  profile_name: string
  description: string | null
  permissions: string[]
  is_system: boolean
  is_active: boolean
  holders: number
}

export interface ProfilesResponse {
  catalog: PermissionCatalog
  profiles: PermissionProfile[]
  is_owner: boolean
}

export interface HeldProfile {
  profile_id: number
  profile_name: string
  is_active: boolean
}

export interface AccessMember {
  uuid: string
  display_name: string | null
  email: string | null
  is_owner: boolean
  profiles: HeldProfile[]
  effective: string[]
  source: 'manage'
}

export interface MembersResponse {
  members: AccessMember[]
  orphans: { uuid: string; profiles: HeldProfile[] }[]
  /** Whether Manage could be reached. 'unavailable' is shown, never faked as empty. */
  directory: { status: 'ready' | 'unavailable'; reason: string | null; source: 'manage' }
}

export const accessApi = {
  profiles: (signal?: AbortSignal) => api.one<ProfilesResponse>('v1/access/profiles', undefined, signal),

  members: (signal?: AbortSignal) => api.one<MembersResponse>('v1/access/members', undefined, signal),

  createProfile: (input: { profile_name: string; description?: string | null; permissions: string[] }) =>
    api.post<PermissionProfile>('v1/access/profiles', input),

  updateProfile: (
    profileId: number,
    changes: Partial<{ profile_name: string; description: string | null; permissions: string[]; is_active: boolean }>,
  ) => api.put<PermissionProfile>(`v1/access/profiles/${profileId}`, changes),

  deleteProfile: (profileId: number, confirm = false) =>
    api.del<{ deleted: boolean; assignments_removed: number }>(
      `v1/access/profiles/${profileId}`,
      confirm ? { confirm: 1 } : undefined,
    ),

  /** The WHOLE set this person holds, so the same call twice is the same state. */
  setMemberProfiles: (uuid: string, profileIds: number[]) =>
    api.put<{ uuid: string; profiles: HeldProfile[]; effective: string[] }>(
      `v1/access/members/${encodeURIComponent(uuid)}`,
      { profile_ids: profileIds },
    ),

  clearMember: (uuid: string) =>
    api.del<{ uuid: string }>(`v1/access/members/${encodeURIComponent(uuid)}`),
}
