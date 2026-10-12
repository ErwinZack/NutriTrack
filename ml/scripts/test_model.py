"""
NutriTrack ML Model Testing
Tests the trained model with sample data
"""

import pandas as pd
import joblib
import numpy as np
from sklearn.metrics import accuracy_score
from pathlib import Path

print("=" * 60)
print("        NutriTrack ML Model Testing")
print("=" * 60)

# Get absolute paths
script_dir = Path(__file__).parent.absolute()
models_dir = script_dir.parent / 'models'
data_dir = script_dir.parent / 'data'

# Check if model exists
model_path = models_dir / 'nutrition_predictor.pkl'
if not model_path.exists():
    print("\n❌ ERROR: Model not found!")
    print(f"Expected location: {model_path}")
    print("Please run train_model.py first to train the model.")
    exit(1)

# Load the trained model
print("\n[1/3] Loading trained model...")
model = joblib.load(str(model_path))
print("✓ Model loaded successfully")

# Create sample data if it doesn't exist
sample_file = data_dir / 'sample_data.csv'
if not sample_file.exists():
    print("\n⚠ Sample data not found. Creating sample test data...")
    data_dir.mkdir(parents=True, exist_ok=True)
    
    sample_data = {
        'bmi': [14.5, 17.5, 18.5, 19.2, 22.5, 23.1, 26.5, 28.0, 32.0, 43.6],
        'expected_status': ['Underweight', 'Underweight', 'Normal', 'Normal', 'Normal', 
                          'Normal', 'Overweight', 'Overweight', 'Obese', 'Obese']
    }
    df = pd.DataFrame(sample_data)
    df.to_csv(sample_file, index=False)
    print(f"✓ Created sample data at {sample_file}")

# Load sample data
print("\n[2/3] Loading sample test data...")
sample_data = pd.read_csv(sample_file)
print(f"✓ Loaded {len(sample_data)} test samples")
print("\nTest data preview:")
print(sample_data)

# Make predictions
print("\n[3/3] Testing predictions...")
X_test = sample_data[['bmi']].values
y_expected = sample_data['expected_status'].values

predictions = model.predict(X_test)

# Display results
print("\n📊 Prediction Results:")
print("-" * 60)
print(f"{'BMI':<10} {'Expected':<15} {'Predicted':<15} {'Match':<10}")
print("-" * 60)

correct = 0
for i in range(len(sample_data)):
    bmi = sample_data.iloc[i]['bmi']
    expected = sample_data.iloc[i]['expected_status']
    predicted = predictions[i]
    match = "✓" if expected == predicted else "✗"
    
    if expected == predicted:
        correct += 1
    
    print(f"{bmi:<10.1f} {expected:<15} {predicted:<15} {match:<10}")

print("-" * 60)

# Calculate accuracy
accuracy = (correct / len(sample_data)) * 100
print(f"\n✓ Test Accuracy: {accuracy:.1f}% ({correct}/{len(sample_data)} correct)")

# Show detailed predictions with recommendations
print("\n" + "=" * 60)
print("        Detailed Prediction Results")
print("=" * 60)

# Recommendations
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

for i in range(len(sample_data)):
    bmi = sample_data.iloc[i]['bmi']
    predicted = predictions[i]
    rec = RECOMMENDATIONS.get(predicted, {})
    
    print(f"\n{i+1}. BMI: {bmi}")
    print(f"   Status: {predicted}")
    print(f"   Recommendation: {rec.get('recommendation', 'N/A')[:60]}...")
    print(f"   Tip: {rec.get('tip', 'N/A')[:60]}...")

print("\n" + "=" * 60)
print("✅ TESTING COMPLETE!")
print("=" * 60)