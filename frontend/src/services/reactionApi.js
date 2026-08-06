// Emoji reactions — /api/reactions/* (shared across task/ticket/project threads)
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const unwrap = (r) => r.data?.data ?? r.data

// The reaction palette the composer offers — must match ReactionService::EMOJIS.
export const REACTION_EMOJIS = ['👍', '❤️', '😄', '🎉', '😮', '😢', '🙏', '🔥']

export const reactionApi = {
  // Batch summary for a whole thread → { [subjectId]: [{emoji,count,mine}] }.
  list: (subjectType, subjectIds) =>
    api.get('/reactions', { params: { subject_type: subjectType, subject_ids: subjectIds } }).then(unwrap).catch(handleErr),
  toggle: (subjectType, subjectId, emoji) =>
    api.post('/reactions/toggle', { subject_type: subjectType, subject_id: subjectId, emoji }).then(unwrap).catch(handleErr),
}

export default reactionApi
