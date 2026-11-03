# Student Performance Prediction System

## Overview

This module implements a Linear Regression-based prediction system for student performance in the grading system. It helps teachers identify at-risk students and provides early intervention recommendations.

## Features

### Core Prediction Engine
- **Algorithm**: Linear Regression (y = mx + b)
- **Input Data**: Historical student performance (quiz scores, assignment scores, exam scores)
- **Output**: Predicted final grade and passing probability
- **Accuracy**: R-squared measurement for model reliability

### Key Components

1. **PredictionService.php** - Core prediction logic
2. **prediction_dashboard.php** - Main dashboard interface
3. **student_comparison.php** - Multi-student comparison tool
4. **api_prediction.php** - RESTful API endpoints
5. **export_predictions.php** - CSV export functionality

### Dashboard Features

- **Class Statistics**: Total students, pass rate, at-risk students
- **Individual Predictions**: Per-student grade predictions with suggestions
- **Visual Charts**: Performance comparison and distribution charts
- **Search & Filter**: Find specific students quickly
- **Export Reports**: Download CSV reports for further analysis

### Student Comparison Tool

- **Multi-student Selection**: Compare 2-6 students simultaneously
- **Performance Rankings**: Visual ranking system with medals
- **Radar Charts**: Multi-dimensional performance comparison
- **Detailed Analysis**: Individual recommendations per student

## Technical Implementation

### Data Sources

The system uses existing database tables:
- `student_list` - Student information
- `assessment_list` - Assessment definitions
- `mark_list` - Student scores
- `published_grades` - Historical final grades

### Linear Regression Model

```php
// Formula: y = mx + b
// y = predicted final grade
// x = current performance metrics
// m = slope (calculated from historical data)
// b = intercept (calculated from historical data)
```

### Training Process

1. **Data Collection**: Gather historical student performance
2. **Feature Engineering**: Calculate current performance averages
3. **Model Training**: Compute slope and intercept using least squares
4. **Validation**: Calculate R-squared for accuracy measurement
5. **Prediction**: Apply model to current students

### Accuracy Metrics

- **R² > 0.7**: High accuracy (reliable predictions)
- **R² 0.4-0.7**: Medium accuracy (use with caution)
- **R² < 0.4**: Low accuracy (insufficient data)

## File Structure

```
grading_system/
├── classes/
│   └── PredictionService.php          # Core prediction logic
├── prediction_dashboard.php           # Main dashboard
├── student_comparison.php             # Comparison tool
├── api_prediction.php                 # API endpoints
├── export_predictions.php             # Export functionality
└── PREDICTION_SYSTEM_README.md        # This documentation
```

## API Endpoints

### Predict Class Performance
```
GET api_prediction.php?action=predict_class&class_id={id}
```

### Get Class Statistics
```
GET api_prediction.php?action=class_stats&class_id={id}
```

### Compare Students
```
POST api_prediction.php?action=compare_students
Body: { class_id: int, student_ids: int[] }
```

### Predict Single Student
```
GET api_prediction.php?action=predict_student&class_id={id}&student_id={id}
```

## Usage Instructions

### Accessing the Dashboard

1. Log in to the grading system
2. Navigate to `prediction_dashboard.php`
3. Select a class from the dropdown
4. View predictions and analysis

### Interpreting Results

#### Student Status Indicators
- **Green (Passing)**: Predicted grade ≥ 75%
- **Red (At Risk)**: Predicted grade < 75% with low pass probability
- **Yellow (Uncertain)**: Borderline performance (65-75%)

#### Performance Metrics
- **Current Performance**: Average of completed assessments
- **Predicted Grade**: Linear regression output
- **Pass Probability**: Likelihood of achieving ≥75%
- **Data Completeness**: Percentage of assessments completed

#### Improvement Suggestions
The system provides personalized recommendations based on:
- Current performance level
- Missing assessment data
- Historical patterns
- Risk factors

### Export Functionality

Download comprehensive reports including:
- Class statistics
- Individual predictions
- Model information
- Improvement suggestions for at-risk students

## Integration with Existing System

### Database Compatibility
- Uses existing SQLite database structure
- No additional tables required
- Compatible with current grading components

### Security
- Inherits existing session management
- Role-based access control
- Data privacy maintained

### Performance
- Optimized queries with indexes
- Efficient calculations
- Minimal server load

## Customization Options

### Grading Components
Teachers can define custom components:
- Quizzes, Assignments, Exams, Projects
- Custom weight percentages
- Flexible grading structure

### Model Parameters
- Minimum data requirements (default: 2 students)
- Confidence thresholds
- Risk classification levels

## Troubleshooting

### Common Issues

#### Low Accuracy (R² < 0.4)
- **Cause**: Insufficient historical data
- **Solution**: Add more student data or use default model

#### No Predictions Showing
- **Cause**: No class selected or no student data
- **Solution**: Select valid class with assessment data

#### Inaccurate Predictions
- **Cause**: Outliers in training data
- **Solution**: Clean data or adjust model parameters

### Error Messages

#### "Class ID is required"
- Select a valid class from the dropdown

#### "Insufficient data for prediction"
- Add more student records or assessments
- Check if students have completed assessments

#### "Unauthorized access"
- Ensure user is logged in with appropriate permissions

## Future Enhancements

### Planned Features
1. **Multi-term Predictions**: Predict across multiple grading periods
2. **Attendance Integration**: Include attendance as a feature
3. **Machine Learning**: Advanced algorithms (Random Forest, Neural Networks)
4. **Real-time Updates**: Live prediction updates as new data enters
5. **Mobile Interface**: Responsive mobile dashboard

### Advanced Analytics
1. **Trend Analysis**: Performance trends over time
2. **Cohort Comparison**: Compare classes/semesters
3. **Intervention Tracking**: Monitor effectiveness of interventions
4. **Predictive Alerts**: Automatic notifications for at-risk students

## Support

For technical support or questions:
- Check the troubleshooting section above
- Review system logs for error details
- Contact system administrator

---

**Version**: 1.0.0  
**Last Updated**: 2025-03-22  
**Compatibility**: PHP 7.4+, SQLite 3.0+
