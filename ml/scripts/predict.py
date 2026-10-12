"""
NutriTrack ML Prediction Script
Called by PHP: python predict.py <bmi>
Returns JSON with status, recommendation, and tip
"""

import sys
import json
import joblib
import numpy as np
import os
from pathlib import Path

# Recommendations mapped to each status category
RECOMMENDATIONS = {
    'Underweight': {
        'recommendation': 'You are underweight. Include more protein-rich foods like eggs, milk, and legumes to gain healthy weight.',
        'tip': 'Eat frequent small meals every 3–4 hours and add healthy snacks like nuts and yogurt.'
    },
    'Normal': {
        'recommendation': 'Your BMI is in the healthy range. Continue eating balanced meals with fruits and vegetables.',
        'tip': 'Stay active with at least 30 minutes of exercise daily and drink enough water.'
    },
    'Overweight': {
        'recommendation': 'You are overweight. Focus on portion control and reduce sugary and fried foods.',
        'tip': 'Use smaller plates, increase daily walking, and choose water over sugary drinks.'
    },
    'Obese': {
        'recommendation': 'You are obese. Lifestyle changes are important. Eat low-calorie, high-fiber meals and seek professional guidance.',
        'tip': 'Focus on vegetables, lean proteins, and daily physical activity. Start with short walks.'
    }
}

def predict(bmi):
    """
    Predict nutritional status using trained ML model
    
    Args:
        bmi (float): Body Mass Index value
        
    Returns:
        dict: Contains status, recommendation, and tip
    """
    
    # Get absolute path to model (works regardless of where script is called from)
    script_dir = Path(__file__).parent.absolute()
    model_path = script_dir.parent / 'models' / 'nutrition_predictor.pkl'
    
    if not model_path.exists():
        return {
            'error': 'Model not found. Please run train_model.py first.',
            'status': 'Error',
            'recommendation': 'ML model not trained yet.',
            'tip': 'Contact system administrator.'
        }
    
    try:
        # Load the trained model
        model = joblib.load(str(model_path))
        
        # Prepare BMI for prediction (needs to be 2D array)
        bmi_array = np.array([[bmi]])
        
        # Make prediction using ML model
        predicted_status = model.predict(bmi_array)[0]
        
        # Get corresponding recommendation and tip
        rec_data = RECOMMENDATIONS.get(predicted_status, {
            'recommendation': 'No recommendation available.',
            'tip': 'Please consult a health professional.'
        })
        
        # Prepare result
        result = {
            'status': predicted_status,
            'recommendation': rec_data['recommendation'],
            'tip': rec_data['tip'],
            'bmi': round(bmi, 2)
        }
        
        # Save last prediction to output folder
        try:
            output_dir = script_dir.parent / 'output'
            output_dir.mkdir(exist_ok=True)
            with open(output_dir / 'last_prediction.json', 'w') as f:
                json.dump(result, f, indent=2)
        except:
            pass  # Don't fail if can't save output
        
        return result
        
    except Exception as e:
        return {
            'error': str(e),
            'status': 'Error',
            'recommendation': f'Prediction failed: {str(e)}',
            'tip': 'Please contact system administrator.'
        }

if __name__ == '__main__':
    # Check if BMI argument provided
    if len(sys.argv) < 2:
        error_result = {
            'error': 'BMI value required',
            'status': 'Error',
            'recommendation': 'No BMI provided',
            'tip': 'Usage: python predict.py <bmi_value>'
        }
        print(json.dumps(error_result))
        sys.exit(1)
    
    try:
        # Get BMI from command line argument
        bmi_value = float(sys.argv[1])
        
        # Validate BMI range
        if bmi_value < 10 or bmi_value > 60:
            error_result = {
                'error': 'Invalid BMI range',
                'status': 'Error',
                'recommendation': 'BMI must be between 10 and 60',
                'tip': 'Please check the input value.'
            }
            print(json.dumps(error_result))
            sys.exit(1)
        
        # Make prediction
        result = predict(bmi_value)
        
        # Output JSON (PHP will read this)
        print(json.dumps(result))
        
    except ValueError:
        error_result = {
            'error': 'Invalid BMI format',
            'status': 'Error',
            'recommendation': 'BMI must be a number',
            'tip': 'Please provide a valid numeric BMI value.'
        }
        print(json.dumps(error_result))
        sys.exit(1)
        
    except Exception as e:
        error_result = {
            'error': str(e),
            'status': 'Error',
            'recommendation': 'Prediction failed',
            'tip': 'Please contact system administrator.'
        }
        print(json.dumps(error_result))
        sys.exit(1)