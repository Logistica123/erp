/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        navy: {
          500: '#2C4C7A',
          600: '#1F3A5F',
          700: '#15345A',
          800: '#0F2844',
          900: '#0B1E36',
        },
        steel: '#3D5A80',
        azure: '#2E75B6',
        accent: '#3A7BD5',
        success: {
          DEFAULT: '#0F7B0F',
          bg: '#E6F4E6',
        },
        danger: {
          DEFAULT: '#B91C1C',
          bg: '#FDECEC',
        },
        warning: {
          DEFAULT: '#B45309',
          bg: '#FEF3C7',
        },
        surface: {
          bg: '#F4F6F9',
          card: '#FFFFFF',
          row: '#F8FAFC',
          hover: '#F1F5F9',
        },
        line: {
          DEFAULT: '#E2E8F0',
          strong: '#CBD5E1',
        },
        ink: {
          DEFAULT: '#0F172A',
          2: '#334155',
          muted: '#64748B',
          faint: '#94A3B8',
        },
      },
      fontFamily: {
        sans: [
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          '"Helvetica Neue"',
          'Arial',
          'sans-serif',
        ],
        mono: ['"SF Mono"', 'Consolas', 'Menlo', 'monospace'],
      },
      boxShadow: {
        brand: '0 2px 8px rgba(46, 117, 182, 0.3)',
      },
    },
  },
  plugins: [],
};
