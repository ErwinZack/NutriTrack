"""
NutriTrack ML Model Training
Learns BMI → Status classification using Machine Learning
"""

import pandas as pd
import joblib
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report
import json
import os
from pathlib import Path

print("=" * 60)
print("        NutriTrack ML Model Training")
print("=" * 60)

# Get absolute paths
script_dir  = Path(__file__).parent.absolute()
data_dir    = script_dir.parent / 'data'
models_dir  = script_dir.parent / 'models'

# -------------------------------------------------------
# [SECURITY] Allowed values for validation
# -------------------------------------------------------
VALID_STATUSES = {'Underweight', 'Normal', 'Overweight', 'Obese'}
VALID_BMI_MIN  = 10.0
VALID_BMI_MAX  = 60.0
MIN_SAMPLES    = 10

# 1. Load training data
print("\n[Step 1/5] Loading training data...")
data_file = data_dir / 'training_data.csv'

if not data_file.exists():
    print(f"\n❌ ERROR: Training data not found at {data_file}")
    print("\nCreating sample training data...")

    # Create data directory if it doesn't exist
    data_dir.mkdir(parents=True, exist_ok=True)

    # Create sample training data
    sample_data = {
        'bmi': [15.5, 16.0, 16.5, 17.0, 17.5, 18.0, 18.3,   # Underweight
                18.5, 19.0, 20.0, 21.0, 22.0, 23.0, 24.0, 24.9,  # Normal
                25.0, 26.0, 27.0, 28.0, 29.0, 29.9,           # Overweight
                30.0, 31.0, 32.0, 35.0, 40.0, 45.0],          # Obese
        'status': ['Underweight'] * 7 + ['Normal'] * 8 + ['Overweight'] * 6 + ['Obese'] * 6
    }

    df = pd.DataFrame(sample_data)
    df.to_csv(data_file, index=False)
    print(f"✓ Created sample training data at {data_file}")

data = pd.read_csv(data_file)
print(f"✓ Loaded {len(data)} rows from CSV")
print(f"✓ Data preview:")
print(data.head())

# -------------------------------------------------------
# [SECURITY] Step 1.5 — Validate data before training
# Prevents model poisoning from malformed or injected data
# -------------------------------------------------------
print("\n[Security] Validating training data...")

# Check required columns exist
if 'bmi' not in data.columns or 'status' not in data.columns:
    print("❌ ERROR: CSV must contain 'bmi' and 'status' columns")
    exit(1)

# Ensure BMI column is numeric — drop rows that can't be converted
data['bmi'] = pd.to_numeric(data['bmi'], errors='coerce')
non_numeric = data['bmi'].isna().sum()
if non_numeric > 0:
    print(f"⚠️  Removed {non_numeric} row(s) with non-numeric BMI values")
data = data.dropna(subset=['bmi', 'status'])

# Strip whitespace from status labels
data['status'] = data['status'].str.strip()

# Validate BMI is within realistic human range
invalid_bmi = data[(data['bmi'] < VALID_BMI_MIN) | (data['bmi'] > VALID_BMI_MAX)]
if not invalid_bmi.empty:
    print(f"⚠️  Removed {len(invalid_bmi)} row(s) with out-of-range BMI "
          f"(valid range: {VALID_BMI_MIN}–{VALID_BMI_MAX})")
    print(invalid_bmi)
data = data[(data['bmi'] >= VALID_BMI_MIN) & (data['bmi'] <= VALID_BMI_MAX)]

# Validate status labels — reject unknown/misspelled categories
invalid_status = data[~data['status'].isin(VALID_STATUSES)]
if not invalid_status.empty:
    print(f"⚠️  Removed {len(invalid_status)} row(s) with invalid status labels:")
    print(invalid_status['status'].unique())
data = data[data['status'].isin(VALID_STATUSES)]

# Minimum sample check — prevent training on near-empty dataset
if len(data) < MIN_SAMPLES:
    print(f"❌ ERROR: Only {len(data)} valid sample(s) remain after validation.")
    print(f"   Minimum required: {MIN_SAMPLES}. Cannot train on insufficient data.")
    exit(1)

print(f"✓ Validation passed — {len(data)} clean sample(s) ready for training")

# 2. Prepare features (X) and labels (y)
print("\n[Step 2/5] Preparing features and labels...")
X = data[['bmi']].values  # Feature: BMI (as 2D array)
y = data['status'].values  # Label: Status

print(f"✓ Feature column: BMI")
print(f"✓ Target categories: {list(set(y))}")
print(f"✓ Distribution:")
for status in set(y):
    count = list(y).count(status)
    print(f"   - {status}: {count} samples")

# 3. Split data into training and testing sets
print("\n[Step 3/5] Splitting data (80% train, 20% test)...")
X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, random_state=42, stratify=y
)
print(f"✓ Training set: {len(X_train)} samples")
print(f"✓ Test set:     {len(X_test)} samples")

# 4. Train the machine learning model
print("\n[Step 4/5] Training RandomForest model...")
model = RandomForestClassifier(
    n_estimators=100,   # 100 decision trees
    random_state=42,    # Reproducible results
    max_depth=10        # Prevent overfitting
)
model.fit(X_train, y_train)
print("✓ Model training complete!")

# 5. Evaluate model accuracy
print("\n[Step 5/5] Evaluating model performance...")
y_pred   = model.predict(X_test)
accuracy = accuracy_score(y_test, y_pred)
print(f"✓ Model Accuracy: {accuracy * 100:.2f}%")

print("\n📊 Detailed Classification Report:")
print(classification_report(y_test, y_pred))

# 6. Save the trained model
print("\n💾 Saving model...")
models_dir.mkdir(parents=True, exist_ok=True)
model_path = models_dir / 'nutrition_predictor.pkl'
joblib.dump(model, model_path)
print(f"✓ Model saved to: {model_path}")

# 7. Save model metadata
model_info = {
    'accuracy':         round(accuracy * 100, 2),
    'training_samples': len(data),
    'test_samples':     len(X_test),
    'features':         ['bmi'],
    'categories':       sorted(list(set(y))),
    'algorithm':        'RandomForestClassifier',
    'n_estimators':     100,
    'version':          '1.0',
    'trained_date':     pd.Timestamp.now().strftime('%Y-%m-%d %H:%M:%S'),
    'validation': {
        'bmi_min':         VALID_BMI_MIN,
        'bmi_max':         VALID_BMI_MAX,
        'valid_statuses':  list(VALID_STATUSES),
        'min_samples':     MIN_SAMPLES,
    }
}

info_path = models_dir / 'model_info.json'
with open(info_path, 'w') as f:
    json.dump(model_info, f, indent=2)
print(f"✓ Model info saved to: {info_path}")

# 8. Test with sample predictions
print("\n🧪 Testing sample predictions:")
test_bmis = [16.5, 22.5, 26.7, 32.3]
for test_bmi in test_bmis:
    predicted_status = model.predict([[test_bmi]])[0]
    print(f"   BMI {test_bmi} → {predicted_status}")

print("\n" + "=" * 60)
print("✅ TRAINING COMPLETE!")
print("=" * 60)
print("\nNext step: Run predict.py to make predictions")
print("Example: python predict.py 22.5")