/** @type {import('tailwindcss').Config} */
const { addDynamicIconSelectors } = require('@iconify/tailwind');

module.exports = {
  content: [
    "./templates/**/*.html.twig",
    "./src/**/*.php",
    "./node_modules/flyonui/dist/js/*.js",
  ],
  safelist: [
    // Avatar color classes - prevent purging
    'bg-gray-500', 'bg-gray-600',
    'bg-purple-500', 'bg-blue-500', 'bg-green-500',
    'bg-yellow-500', 'bg-indigo-500', 'bg-teal-500',
    'bg-orange-500', 'bg-cyan-500',
    'text-white', 'text-black',
    // Status badge color classes - prevent purging
    'bg-gray-100', 'text-gray-800',
    'bg-blue-100', 'text-blue-800',
    'bg-blue-200', 'text-blue-900',
    'bg-blue-300', 'text-blue-900',
    'bg-blue-400', 'text-blue-900',
    'bg-blue-500', 'text-white',
    'bg-blue-600', 'text-white',
    'bg-blue-700', 'text-white',
    'bg-purple-100', 'text-purple-800',
    'bg-orange-100', 'text-orange-800',
    'bg-yellow-100', 'text-yellow-800',
    'bg-yellow-200', 'text-yellow-900',
    'bg-green-100', 'text-green-800',
    'bg-green-200', 'text-green-900',
    'bg-green-300', 'text-green-900',
    'bg-red-100', 'text-red-800',
    // FlyonUI stepper block
    'text-bg-soft-neutral',
    'min-h-7.5', 'min-w-7.5', 'size-7.5',
  ],
  theme: {
    extend: {
      colors: {
        // Meta Blue palette
        'meta-blue': {
          50: '#EBF4FF',
          100: '#D6E8FF',
          200: '#B3D4FF',
          300: '#80BAFF',
          400: '#4D9AFF',
          500: '#1877F2', // Primary Meta Blue
          600: '#166FE5',
          700: '#1456CC',
          800: '#0F3F99',
          900: '#0A2966',
        },
        // Meta Gray palette
        'meta-gray': {
          50: '#FAFBFC',
          100: '#F0F2F5', // Primary Meta Gray
          200: '#E4E6EA',
          300: '#CCD0D5',
          400: '#8A8D91',
          500: '#65676B', // Secondary text
          600: '#4B4F56',
          700: '#365899',
          800: '#29487D',
          900: '#1C3A5C',
        },
        // Status colors
        'status': {
          'draft': '#6B7280',
          'pending': '#3B82F6',
          'approved': '#10B981',
          'denied': '#EF4444',
          'compliance': '#F59E0B',
        },
        // Text colors
        'meta-text': {
          'primary': '#050505',
          'secondary': '#65676B',
          'tertiary': '#8A8D91',
        },
      },
      borderRadius: {
        'meta': '8px',
        'meta-sm': '6px',
        'meta-lg': '12px',
      },
      boxShadow: {
        'meta': '0 1px 2px rgba(0, 0, 0, 0.1)',
        'meta-md': '0 2px 4px rgba(0, 0, 0, 0.1)',
        'meta-lg': '0 2px 4px rgba(0, 0, 0, 0.1), 0 8px 16px rgba(0, 0, 0, 0.1)',
        'meta-hover': '0 4px 8px rgba(0, 0, 0, 0.12), 0 2px 4px rgba(0, 0, 0, 0.08)',
      },
      spacing: {
        '18': '4.5rem',
        '88': '22rem',
      },
      fontFamily: {
        'sans': ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
      },
      animation: {
        'fade-in': 'fadeIn 0.2s ease-in-out',
        'slide-in': 'slideIn 0.3s ease-out',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideIn: {
          '0%': { transform: 'translateY(-10px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
      },
    },
  },
  plugins: [
    require('flyonui')({
      themes: ["light --default", "dark --prefersdark", "claude", "corporate"]
    }),
    addDynamicIconSelectors({
      collections: ['tabler']
    }),
  ],
  // FlyonUI theme configuration (also passed via plugin options above)
  flyonui: {
    themes: ["light --default", "dark --prefersdark", "claude", "corporate"]
  }
}
