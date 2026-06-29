import forms from '@tailwindcss/forms'

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // ── Sangoe Brand Purple (primary) ─────────────────────────────
        primary: {
          50:  '#f5f3ff',
          100: '#ede9fe',
          200: '#ddd6fe',
          300: '#c4b5fd',
          400: '#a78bfa',
          500: '#8b5cf6',
          600: '#7C3AED',   // Brand Purple
          700: '#6d28d9',
          800: '#5b21b6',
          900: '#4c1d95',
          950: '#2e1065',
        },
        // ── Status Colors ─────────────────────────────────────────────
        success:  { DEFAULT: '#10B981', light: '#d1fae5', dark: '#065f46' },
        warning:  { DEFAULT: '#F59E0B', light: '#fef3c7', dark: '#92400e' },
        danger:   { DEFAULT: '#EF4444', light: '#fee2e2', dark: '#991b1b' },

        // ── Dark Mode Surfaces (Sangoe spec) ──────────────────────────
        dark: {
          bg:      '#0b0b16',   // global background
          section: '#0e0e1a',   // section background
          card:    '#1c1c2e',   // card background
          border:  'rgba(255,255,255,0.07)',
          text:    '#edeaf8',   // warm white
          muted:   '#8b85a8',   // muted purple-gray
        },

        // ── Light Mode Surfaces ───────────────────────────────────────
        light: {
          bg:      '#ffffff',
          secondary: '#f9fafb',
          border:  'rgba(0,0,0,0.05)',
          text:    '#111827',
          muted:   '#6b7280',
        },

        surface: {
          light: '#f9fafb',
          dark:  '#1c1c2e',
        },
      },

      fontFamily: {
        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
      },

      fontSize: {
        // Clamp-based responsive headings per Sangoe spec
        'hero': ['clamp(2.4rem, 4.8vw, 4rem)', { lineHeight: '1.1', letterSpacing: '-0.03em', fontWeight: '950' }],
        'display': ['clamp(1.8rem, 3.5vw, 2.8rem)', { lineHeight: '1.15', letterSpacing: '-0.02em', fontWeight: '800' }],
      },

      letterSpacing: {
        tight:   '-0.03em',
        tighter: '-0.04em',
        label:   '0.06em',
      },

      borderRadius: {
        sm:    '4px',
        md:    '8px',
        lg:    '12px',
        xl:    '16px',
        '2xl': '20px',
        '3xl': '24px',
      },

      boxShadow: {
        sm:    '0 1px 2px 0 rgba(0,0,0,0.05)',
        md:    '0 4px 6px -1px rgba(0,0,0,0.1)',
        lg:    '0 10px 15px -3px rgba(0,0,0,0.1)',
        xl:    '0 20px 25px -5px rgba(0,0,0,0.15)',
        // Purple glow — Sangoe brand
        glow:        '0 0 20px rgba(124,58,237,0.35)',
        'glow-sm':   '0 0 12px rgba(124,58,237,0.25)',
        'glow-lg':   '0 0 40px rgba(124,58,237,0.4)',
        // Trust pill hover lift
        'lift':      '0 4px 20px rgba(0,0,0,0.3)',
        'lift-purple': '0 8px 30px rgba(124,58,237,0.3)',
      },

      backgroundImage: {
        // Sangoe brand gradients
        'purple-gradient':  'linear-gradient(135deg, #7C3AED, #5b21b6)',
        'purple-glow':      'radial-gradient(ellipse at top, rgba(124,58,237,0.15) 0%, transparent 70%)',
        'dark-gradient':    'linear-gradient(180deg, #0e0e1a 0%, #0b0b16 100%)',
        'hero-gradient':    'radial-gradient(ellipse 80% 50% at 50% 0%, rgba(124,58,237,0.2) 0%, transparent 60%)',
        'card-gradient':    'linear-gradient(135deg, rgba(124,58,237,0.08) 0%, rgba(91,33,182,0.05) 100%)',
      },

      animation: {
        'fade-in':     'fadeIn 150ms ease',
        'slide-up':    'slideUp 220ms ease',
        'slide-down':  'slideDown 200ms ease',
        'scale-in':    'scaleIn 200ms ease',
        'shimmer':     'shimmer 2s infinite',
        'bounce-in':   'bounceIn 300ms ease',
        'orbit':       'orbit 12s linear infinite',
        'orbit-rev':   'orbitRev 16s linear infinite',
        'swipe-pulse': 'swipePulse 1.4s ease-in-out infinite',
        'glow-pulse':  'glowPulse 3s ease-in-out infinite',
        'float':       'float 4s ease-in-out infinite',
        'spin-slow':   'spin 8s linear infinite',
        'tilt-in':     'tiltIn 0.35s ease forwards',
      },

      keyframes: {
        fadeIn:     { from: { opacity: 0 },            to: { opacity: 1 } },
        slideUp:    { from: { transform: 'translateY(16px)', opacity: 0 }, to: { transform: 'translateY(0)', opacity: 1 } },
        slideDown:  { from: { transform: 'translateY(-10px)', opacity: 0 }, to: { transform: 'translateY(0)', opacity: 1 } },
        scaleIn:    { from: { transform: 'scale(0.95)', opacity: 0 }, to: { transform: 'scale(1)', opacity: 1 } },
        shimmer:    { from: { backgroundPosition: '-200% 0' }, to: { backgroundPosition: '200% 0' } },
        bounceIn: {
          '0%':   { transform: 'scale(0.3)', opacity: 0 },
          '60%':  { transform: 'scale(1.05)' },
          '100%': { transform: 'scale(1)', opacity: 1 },
        },
        orbit: {
          '0%':   { transform: 'rotate(0deg) translateX(120px) rotate(0deg)' },
          '100%': { transform: 'rotate(360deg) translateX(120px) rotate(-360deg)' },
        },
        orbitRev: {
          '0%':   { transform: 'rotate(0deg) translateX(170px) rotate(0deg)' },
          '100%': { transform: 'rotate(-360deg) translateX(170px) rotate(360deg)' },
        },
        swipePulse: {
          '0%, 100%': { transform: 'translateX(0)' },
          '50%':      { transform: 'translateX(4px)' },
        },
        glowPulse: {
          '0%, 100%': { opacity: 0.6, transform: 'scale(1)' },
          '50%':      { opacity: 1,   transform: 'scale(1.05)' },
        },
        float: {
          '0%, 100%': { transform: 'translateY(0)' },
          '50%':      { transform: 'translateY(-8px)' },
        },
        tiltIn: {
          '0%':   { transform: 'perspective(800px) rotateX(6deg) translateY(8px)', opacity: 0 },
          '100%': { transform: 'perspective(800px) rotateX(0deg) translateY(0)',   opacity: 1 },
        },
      },
    },
  },
  plugins: [forms],
}
