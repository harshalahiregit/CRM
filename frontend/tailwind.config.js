import forms from '@tailwindcss/forms'

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          50:  '#eff6ff',
          100: '#dbeafe',
          200: '#bfdbfe',
          300: '#93c5fd',
          400: '#60a5fa',
          500: '#3b82f6',
          600: '#2563EB',
          700: '#1d4ed8',
          800: '#1e40af',
          900: '#1e3a8a',
        },
        success:  { DEFAULT: '#10B981', light: '#d1fae5', dark: '#065f46' },
        warning:  { DEFAULT: '#F59E0B', light: '#fef3c7', dark: '#92400e' },
        danger:   { DEFAULT: '#EF4444', light: '#fee2e2', dark: '#991b1b' },
        surface: {
          light: '#F3F4F6',
          dark:  '#1F2937',
        },
      },
      fontFamily: {
        sans: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'sans-serif'],
      },
      borderRadius: {
        sm:  '4px',
        md:  '8px',
        lg:  '12px',
        xl:  '16px',
        '2xl': '20px',
      },
      boxShadow: {
        sm:  '0 1px 2px 0 rgba(0,0,0,0.05)',
        md:  '0 4px 6px -1px rgba(0,0,0,0.1)',
        lg:  '0 10px 15px -3px rgba(0,0,0,0.1)',
        xl:  '0 20px 25px -5px rgba(0,0,0,0.15)',
        glow:'0 0 20px rgba(37,99,235,0.3)',
      },
      animation: {
        'fade-in':     'fadeIn 150ms ease',
        'slide-up':    'slideUp 200ms ease',
        'slide-down':  'slideDown 200ms ease',
        'scale-in':    'scaleIn 200ms ease',
        shimmer:       'shimmer 2s infinite',
        'bounce-in':   'bounceIn 300ms ease',
      },
      keyframes: {
        fadeIn:   { from: { opacity: 0 }, to: { opacity: 1 } },
        slideUp:  { from: { transform: 'translateY(100%)' }, to: { transform: 'translateY(0)' } },
        slideDown:{ from: { transform: 'translateY(-10px)', opacity: 0 }, to: { transform: 'translateY(0)', opacity: 1 } },
        scaleIn:  { from: { transform: 'scale(0.95)', opacity: 0 }, to: { transform: 'scale(1)', opacity: 1 } },
        shimmer:  { from: { backgroundPosition: '-200% 0' }, to: { backgroundPosition: '200% 0' } },
        bounceIn: {
          '0%':   { transform: 'scale(0.3)', opacity: 0 },
          '60%':  { transform: 'scale(1.05)' },
          '100%': { transform: 'scale(1)', opacity: 1 },
        },
      },
    },
  },
  plugins: [forms],
}
