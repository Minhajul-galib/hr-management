# Light & Dark Theme System

## Overview
A comprehensive theme system has been implemented with support for Light, Dark, and System (auto) modes. The theme system provides a seamless user experience with smooth transitions and persistent preferences.

## Features

### 🌓 **Three Theme Modes**
1. **Light Mode** ☀️ - Classic light theme with bright backgrounds
2. **Dark Mode** 🌙 - Modern dark theme with dark backgrounds  
3. **System Mode** 💻 - Automatically follows system preference

### 🔄 **Smart Theme Detection**
- **System Preference**: Automatically detects user's OS theme preference
- **Real-time Updates**: Responds to system theme changes without page reload
- **Persistent Storage**: Remembers user's theme choice in localStorage

### 🎨 **Comprehensive Styling**
- **Sidebar**: Full dark mode support with proper contrast
- **Navigation**: Header and mobile menu with theme-aware colors
- **Notifications**: Dark mode compatible notification panel
- **Tooltips**: Theme-aware tooltip styling
- **Dropdowns**: Consistent dark mode dropdown menus

### ⚡ **Smooth Transitions**
- **300ms Transitions**: Smooth color transitions between themes
- **No Flash**: Prevents white flash when switching to dark mode
- **Consistent Animation**: All elements transition smoothly

## Usage

### For Users
1. **Theme Toggle**: Click the theme icon in the header (☀️/🌙/💻)
2. **Cycle Modes**: Each click cycles through Light → Dark → System
3. **Auto-Detection**: System mode automatically follows your OS preference

### Theme Icons
- ☀️ **Sun Icon**: Currently in Light mode
- 🌙 **Moon Icon**: Currently in Dark mode  
- 💻 **Monitor Icon**: Currently in System mode (follows OS)

## Technical Implementation

### Vue 3 Composition API
```javascript
const darkMode = ref(false);
const themePreference = ref('system'); // 'light', 'dark', 'system'

const applyTheme = (theme) => {
    const html = document.documentElement;
    
    if (theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        html.classList.add('dark');
        darkMode.value = true;
    } else {
        html.classList.remove('dark');
        darkMode.value = false;
    }
};
```

### Tailwind CSS Classes
All components use Tailwind's dark mode classes:
```vue
<div class="bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
    Content with theme support
</div>
```

### System Theme Detection
```javascript
const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
mediaQuery.addEventListener('change', handleSystemThemeChange);
```

## Styling Guidelines

### Color Scheme
**Light Mode:**
- Background: `bg-white`, `bg-gray-100`
- Text: `text-gray-900`, `text-gray-600`
- Borders: `border-gray-200`

**Dark Mode:**
- Background: `dark:bg-gray-800`, `dark:bg-gray-900`
- Text: `dark:text-gray-100`, `dark:text-gray-300`
- Borders: `dark:border-gray-700`

### Component Examples
```vue
<!-- Sidebar -->
<aside class="bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700">

<!-- Navigation Item -->
<Link class="text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">

<!-- Button -->
<button class="bg-blue-600 dark:bg-blue-700 text-white hover:bg-blue-700 dark:hover:bg-blue-600">
```

## Browser Support
- **Modern Browsers**: Full support for CSS custom properties and media queries
- **System Detection**: `prefers-color-scheme` media query support
- **localStorage**: For persistent theme preferences
- **CSS Transitions**: Smooth theme switching animations

## Accessibility Features
- **High Contrast**: Proper contrast ratios in both themes
- **Focus Indicators**: Theme-aware focus styles
- **Screen Readers**: Proper ARIA labels for theme toggle
- **Reduced Motion**: Respects `prefers-reduced-motion` setting

## Performance
- **CSS-Only Transitions**: No JavaScript animations for better performance
- **Minimal Overhead**: Lightweight theme detection and switching
- **Efficient Updates**: Only updates necessary DOM classes
- **No Flash**: Prevents layout shifts during theme changes

## Customization

### Adding New Components
When creating new components, always include dark mode classes:
```vue
<template>
    <div class="bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">
        <!-- Component content -->
    </div>
</template>
```

### Custom Colors
Add custom dark mode colors in `tailwind.config.js`:
```javascript
theme: {
    extend: {
        colors: {
            'custom-light': '#f8fafc',
            'custom-dark': '#1e293b'
        }
    }
}
```

## Files Modified
- `resources/js/Layouts/AuthenticatedLayout.vue` - Main theme implementation
- `resources/css/dashboard.css` - Additional dark mode styles
- `tailwind.config.js` - Dark mode configuration (already enabled)

## Future Enhancements
- Theme-specific component variants
- Custom color scheme builder
- Per-page theme overrides
- Theme transition animations
- High contrast mode support