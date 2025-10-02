# Store Module - Visual Design Reference

## Color Palette

### Primary Colors
```
Purple Blue: #667eea
Deep Purple: #764ba2
Dark Text: #2d3748
Medium Text: #4a5568
Light Text: #718096
Border: #e2e8f0
Background: #f7fafc
```

### Store Type Colors
```
Retail Store:     #1976d2 (Blue)
Warehouse:        #388e3c (Green)
Distribution:     #f57c00 (Orange)
Flagship:         #c2185b (Pink)
Outlet:           #7b1fa2 (Purple)
```

### Status Colors
```
Success:          #48bb78 (Green)
Error:            #f56565 (Red)
Warning:          #ed8936 (Orange)
Info:             #4299e1 (Blue)
```

## Typography

### Font Stack
```css
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
```

### Font Sizes
```
Heading 1:        2em (32px)
Heading 2:        1.3em (20.8px)
Heading 3:        1.2em (19.2px)
Body:             1em (16px)
Small:            0.95em (15.2px)
Tiny:             0.9em (14.4px)
Label:            14px
Button:           14px
```

### Font Weights
```
Regular:          400
Medium:           500
Semi-Bold:        600
Bold:             700
Extra Bold:       800
```

## Spacing System

### Margin/Padding Scale
```
0:    0px
1:    8px
2:    12px
3:    15px
4:    20px
5:    25px
6:    30px
7:    35px
```

## Border Radius

```
Small:            8px   (buttons, inputs)
Medium:           12px  (cards, forms)
Large:            16px  (popups)
X-Large:          20px  (main containers)
Round:            50%   (badges, icons)
```

## Shadows

### Light Shadows
```css
xs:  0 1px 3px rgba(0,0,0,0.1)
sm:  0 2px 8px rgba(0,0,0,0.1)
md:  0 4px 15px rgba(0,0,0,0.12)
lg:  0 8px 30px rgba(0,0,0,0.15)
xl:  0 10px 40px rgba(0,0,0,0.15)
```

### Colored Shadows (Buttons)
```css
primary: 0 4px 15px rgba(102, 126, 234, 0.3)
hover:   0 6px 25px rgba(102, 126, 234, 0.4)
```

## Animations

### Duration
```
Fast:             0.15s
Normal:           0.3s
Slow:             0.5s
Very Slow:        0.6s
```

### Easing Functions
```
ease-out:         cubic-bezier(0, 0, 0.2, 1)
ease-in:          cubic-bezier(0.4, 0, 1, 1)
ease-in-out:      cubic-bezier(0.4, 0, 0.2, 1)
bounce:           cubic-bezier(0.175, 0.885, 0.32, 1.275)
```

### Common Animations
```css
/* Fade In Up */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Slide Down */
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Pulse */
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

/* Spin */
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
```

## Component Styles

### Button Variants

#### Primary Button
```css
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
color: white;
padding: 12px 24px;
border-radius: 12px;
box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
```

#### Secondary Button
```css
background: linear-gradient(135deg, #718096 0%, #4a5568 100%);
color: white;
padding: 12px 24px;
border-radius: 12px;
box-shadow: 0 4px 15px rgba(113, 128, 150, 0.3);
```

#### Outline Button
```css
background: white;
color: #667eea;
border: 2px solid #667eea;
padding: 12px 24px;
border-radius: 12px;
box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
```

### Card Styles

#### Standard Card
```css
background: rgba(255, 255, 255, 0.98);
border-radius: 20px;
padding: 30px;
box-shadow: 0 10px 40px rgba(0,0,0,0.15);
border: 1px solid rgba(255,255,255,0.3);
backdrop-filter: blur(10px);
```

#### Stat Card
```css
background: rgba(255, 255, 255, 0.98);
border-radius: 20px;
padding: 25px;
box-shadow: 0 8px 30px rgba(0,0,0,0.12);
border: 1px solid rgba(255,255,255,0.5);
/* Top border on hover */
border-top: 4px solid gradient;
```

### Form Elements

#### Input Field
```css
padding: 12px 16px;
border: 2px solid #e2e8f0;
border-radius: 12px;
font-size: 14px;
transition: all 0.3s ease;

/* Focus state */
border-color: #667eea;
box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
transform: translateY(-2px);
```

#### Select Dropdown
```css
padding: 12px 18px;
border: 2px solid #e2e8f0;
border-radius: 12px;
font-size: 14px;
cursor: pointer;
```

#### Textarea
```css
padding: 12px 16px;
border: 2px solid #e2e8f0;
border-radius: 12px;
resize: vertical;
min-height: 100px;
```

### Badge Styles

#### Store Type Badges
```css
padding: 6px 12px;
border-radius: 20px;
font-size: 11px;
font-weight: 700;
text-transform: uppercase;
letter-spacing: 0.5px;
box-shadow: 0 2px 8px rgba(0,0,0,0.1);
```

## Layout Grid

### Container Widths
```
Small:      800px
Medium:     1200px
Large:      1400px
X-Large:    1600px
```

### Grid Configurations
```css
/* Stats Grid */
grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
gap: 20px;

/* Store Cards */
grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
gap: 20px;

/* Form Rows */
grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
gap: 20px;
```

## Responsive Breakpoints

```
Mobile:       < 768px
Tablet:       768px - 1024px
Desktop:      > 1024px
Large:        > 1440px
```

### Mobile Adjustments
```css
@media (max-width: 768px) {
    /* Single column layouts */
    grid-template-columns: 1fr;
    
    /* Reduced padding */
    padding: 20px;
    
    /* Smaller font sizes */
    font-size: 0.9em;
    
    /* Full width buttons */
    width: 100%;
}
```

## Icon Usage

### Font Awesome Icons
```html
Store:              fa-store, fa-store-alt
Map:                fa-map, fa-map-marked-alt
Location:           fa-map-marker-alt
Info:               fa-info-circle
Clock:              fa-clock
Contact:            fa-address-book
Plus:               fa-plus-circle
Edit:               fa-edit
Delete:             fa-trash
View:               fa-eye
Filter:             fa-filter
Search:             fa-search
Warehouse:          fa-warehouse
Distribution:       fa-truck-loading
Flagship:           fa-star
Outlet:             fa-shopping-bag
```

## Interaction States

### Hover Effects
```css
transform: translateY(-2px) or translateY(-5px);
box-shadow: increased intensity;
opacity: changes for transparency effects;
```

### Active/Pressed
```css
transform: translateY(0) or scale(0.98);
```

### Disabled
```css
opacity: 0.6;
cursor: not-allowed;
pointer-events: none;
```

### Focus
```css
outline: none;
border-color: #667eea;
box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
```

## Quick Copy Snippets

### Gradient Background
```css
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
```

### Glass Card
```css
background: rgba(255, 255, 255, 0.98);
backdrop-filter: blur(10px);
border: 1px solid rgba(255,255,255,0.3);
```

### Button Base
```css
padding: 12px 24px;
border-radius: 12px;
font-weight: 600;
transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
```

### Smooth Hover
```css
transition: all 0.3s ease;
&:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
}
```

---

**Reference Guide for:** Store Management Module  
**Version:** 2.0  
**Last Updated:** October 2, 2025
