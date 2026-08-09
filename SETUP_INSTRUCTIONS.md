# ERP Analytics Dashboard - Setup Instructions

## 🚀 Quick Start

Your ERP Analytics Dashboard is now ready! Here's how to access it:

### 1. **Access the Dashboard**
- **Home Page**: http://127.0.0.1:8000/ (New ERP landing page)
- **Dashboard**: http://127.0.0.1:8000/dashboard (Analytics dashboard)
- **Alternative**: http://127.0.0.1:8000/home (redirects to dashboard)

### 2. **Login Required**
The dashboard requires authentication. If you don't have an account:
1. Go to http://127.0.0.1:8000/register to create an account
2. Or use http://127.0.0.1:8000/login if you already have one

### 3. **Sample Data**
The system includes sample data for testing:
- 10 sample users with different roles
- Revenue and expense data
- Department performance metrics
- Recent activity logs

## 📊 Dashboard Features

### **Key Metrics Cards**
- Total Employees with active percentage
- Monthly Revenue with growth trends
- Today's Attendance with late arrivals
- Task Completion rates

### **Interactive Charts**
- Revenue vs Expenses (Area Chart)
- Attendance Distribution (Donut Chart)
- Department Performance (Progress Bars)
- Task Progress (Horizontal Bar Chart)

### **Real-time Data**
- Recent Activities feed
- Department performance tracking
- Live attendance monitoring

## 🔧 Development Commands

### **Setup Dashboard (if needed)**
```bash
php artisan dashboard:setup
```

### **Build Assets**
```bash
npm run build
```

### **Development Mode**
```bash
npm run dev
```

### **Clear Cache**
```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

## 📱 Responsive Design

The dashboard is fully responsive and works on:
- Desktop computers
- Tablets
- Mobile phones

## 🎨 Customization

### **Colors & Styling**
- Edit `resources/css/dashboard.css` for custom styles
- Modify `tailwind.config.js` for theme changes

### **Data Sources**
- Update `app/Http/Controllers/DashboardController.php` to connect real data
- Modify chart data in the controller methods

### **Components**
- `resources/js/Components/MetricCard.vue` - Metric display cards
- `resources/js/Components/ChartCard.vue` - Chart containers
- `resources/js/Components/DepartmentProgress.vue` - Department widgets

## 🔐 Security Features

- Role-based access control
- Authentication required
- CSRF protection
- Input validation

## 📈 Next Steps

1. **Connect Real Data**: Replace sample data with actual ERP data
2. **Add More Modules**: Extend with attendance, payroll, etc.
3. **Custom Reports**: Add export functionality
4. **Real-time Updates**: Implement WebSocket connections
5. **Mobile App**: Consider mobile application development

## 🆘 Troubleshooting

### **Page Not Loading**
- Make sure Laravel server is running: `php artisan serve`
- Check if assets are built: `npm run build`

### **Login Issues**
- Run: `php artisan dashboard:setup` to create sample users
- Default password for sample users: `password`

### **Chart Not Displaying**
- Ensure ApexCharts is installed: `npm install`
- Build assets: `npm run build`

### **Permission Errors**
- Check user roles in the database
- Verify authentication middleware

## 📞 Support

The dashboard is built with:
- Laravel 12.0
- Vue 3 with Composition API
- Inertia.js for SPA experience
- Tailwind CSS for styling
- ApexCharts for visualizations
- Lucide Icons for UI elements

Enjoy your new ERP Analytics Dashboard! 🎉