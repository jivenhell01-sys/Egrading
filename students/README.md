# Student Portal - ESSUC Grading System

## Overview
This directory contains the student portal for the ESSUC Grading System. It provides a secure interface for students to view their grades and personal information.

## Files

### Core Files
- **index.php** - Main student dashboard and grades viewer
- **login.php** - Student authentication page
- **logout.php** - Session termination handler
- **.htaccess** - Security and routing configuration

## Features

### Authentication System
- Secure student login using student number (format: XX-XXXXX)
- Session-based authentication
- Automatic redirect to login if not authenticated
- Secure logout with session destruction

### Student Dashboard
- Personal information display
- Grade viewing by subject and quarter
- School year and semester information
- Responsive design for mobile devices

### Security Features
- Input validation and sanitization
- SQL injection protection
- Session security
- Access control via .htaccess
- Security headers implementation

## Access Requirements

### Student Login
- Student must be registered in the system
- Valid student number format: XX-XXXXX (e.g., 25-12345)
- Student must be assigned to a class

### Database Requirements
- Student must exist in `student_list` table
- Must be assigned to a class in `class_list` table
- Grades must be published in `published_grades` table

## Usage

### For Students
1. Navigate to the students directory
2. Enter your student number in the format XX-XXXXX
3. View your dashboard with personal information
4. Click "My Grades" to see published grades
5. Logout when finished

### For Administrators
- Ensure students are properly registered in the main system
- Publish grades through the admin panel for students to view
- Monitor student access through logs

## Technical Details

### Session Variables
- `student_logged_in` - Authentication status
- `student_number` - Student's unique identifier
- `student_id` - Database ID
- `student_name` - Student's full name
- `login_time` - Session start time

### Security Measures
- Input validation for student numbers
- Prepared statements for database queries
- Session regeneration on login
- Secure cookie handling
- XSS protection

## Browser Compatibility
- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+

## Mobile Responsiveness
- Fully responsive design
- Touch-friendly interface
- Optimized for mobile viewing

## Support
For technical support or issues, contact the system administrator.
