/**
 * The primary gradient, in one place.
 *
 * It is copy-pasted into 274 files as a literal, which means the product cannot
 * be restyled without a find-and-replace across all of them — and a
 * find-and-replace across 274 files is how three of them end up slightly
 * different. BannedPatternsTest guards against new copies; this is what to use
 * instead.
 *
 * Lives under components/ui/ deliberately: that directory is where shared
 * styling belongs, and it is the one place the guard exempts.
 */
export const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

/** The same, for a surface that needs the darker end (hover, pressed). */
export const GRAD_DEEP = 'linear-gradient(135deg,#6d28d9,#4c1d95)'
