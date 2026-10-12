"""
NutriTrack ML API
Flask REST API for PHP integration

[SECURITY] Hardened with:
- API token authentication on all prediction routes
- Rate limiting to prevent abuse
- Restricted CORS to localhost only
- Input validation on all endpoints
- Debug mode disabled
- Bound to localhost only
"""
from dotenv import load_dotenv
load_dotenv(dotenv_path='../../.env')  # points to your NutriTrack .env

from flask import Flask, request, jsonify
from flask_cors import CORS
from flask_limiter import Limiter
from flask_limiter.util import get_remote_address
import sys
import os

# Add parent directory to path to import predict module
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from scripts.predict import predict

app = Flask(__name__)

# -------------------------------------------------------
# [SECURITY] Load API secret token from environment
# Set this in your .env or system environment:
#   NUTRITRACK_API_TOKEN=your_secret_token_here
# -------------------------------------------------------
API_TOKEN = os.environ.get('NUTRITRACK_API_TOKEN', '')

if not API_TOKEN:
    print("⚠️  WARNING: NUTRITRACK_API_TOKEN is not set in environment.")
    print("   Set it before running in production.")

# -------------------------------------------------------
# [SECURITY] CORS — Restrict to localhost only
# Only the NutriTrack PHP app running locally can call this
# -------------------------------------------------------
CORS(app, resources={
    r"/*": {
        "origins": [
            "http://localhost",
            "http://127.0.0.1",
            "http://localhost:80",
            "http://127.0.0.1:80"
        ]
    }
})

# -------------------------------------------------------
# [SECURITY] Rate Limiting
# Prevents brute force and API abuse
# -------------------------------------------------------
limiter = Limiter(
    get_remote_address,
    app=app,
    default_limits=["200 per day", "50 per hour"],
    storage_uri="memory://"
)

# -------------------------------------------------------
# [SECURITY] Token Authentication Helper
# Call this at the start of any protected route
# -------------------------------------------------------
def verify_token():
    """
    Checks Authorization header for valid Bearer token.
    Returns True if valid, False otherwise.
    """
    auth_header = request.headers.get('Authorization', '')
    if not auth_header.startswith('Bearer '):
        return False
    token = auth_header.split('Bearer ', 1)[1].strip()
    return token == API_TOKEN and API_TOKEN != ''


# -------------------------------------------------------
# PUBLIC ROUTES (no token required)
# -------------------------------------------------------

@app.route('/', methods=['GET'])
def home():
    """API home endpoint — public info only"""
    return jsonify({
        'service':  'NutriTrack ML API',
        'version':  '1.0',
        'status':   'running',
        'endpoints': {
            '/predict': 'POST - Make BMI prediction (requires token)',
            '/health':  'GET  - Check API health'
        }
    })


@app.route('/health', methods=['GET'])
@limiter.limit("30 per minute")
def health():
    """Health check endpoint — public, rate limited"""
    return jsonify({
        'status': 'healthy',
        'model':  'loaded'
    })


# -------------------------------------------------------
# PROTECTED ROUTES (token required)
# -------------------------------------------------------

@app.route('/predict', methods=['POST'])
@limiter.limit("60 per minute")   # Max 60 predictions per minute per IP
def make_prediction():
    """
    Prediction endpoint — requires Bearer token in Authorization header.
    Expects JSON: {"bmi": 22.5}
    Returns: {"status": "Normal", "recommendation": "...", "tip": "..."}
    """

    # [SECURITY] Verify API token before processing
    if not verify_token():
        return jsonify({
            'error':  'Unauthorized — valid API token required',
            'status': 'Error'
        }), 401

    try:
        data = request.get_json(silent=True)

        # [SECURITY] Reject missing or malformed JSON body
        if not data or 'bmi' not in data:
            return jsonify({
                'error':  'BMI value required. Send JSON: {"bmi": 22.5}',
                'status': 'Error'
            }), 400

        # [SECURITY] Validate BMI is numeric
        try:
            bmi = float(data['bmi'])
        except (ValueError, TypeError):
            return jsonify({
                'error':  'BMI must be a numeric value',
                'status': 'Error'
            }), 400

        # [SECURITY] Validate BMI is within realistic human range
        if bmi < 10 or bmi > 60:
            return jsonify({
                'error':  'BMI out of valid range (10.0 – 60.0)',
                'status': 'Error'
            }), 400

        # Make prediction via ML model
        result = predict(bmi)

        return jsonify(result), 200

    except Exception as e:
        # [SECURITY] Never expose raw exception details to caller
        app.logger.error(f"Prediction error: {str(e)}")
        return jsonify({
            'error':  'Internal server error — prediction failed',
            'status': 'Error'
        }), 500


# -------------------------------------------------------
# RATE LIMIT ERROR HANDLER
# -------------------------------------------------------
@app.errorhandler(429)
def rate_limit_exceeded(e):
    return jsonify({
        'error':  'Too many requests — please slow down',
        'status': 'Error'
    }), 429


# -------------------------------------------------------
# ENTRY POINT
# [SECURITY] debug=False, bound to localhost only
# -------------------------------------------------------
if __name__ == '__main__':
    print("=" * 60)
    print("       NutriTrack ML API Starting")
    print("=" * 60)
    print("\nAPI running at: http://127.0.0.1:5000")
    print("\nEndpoints:")
    print("  GET  /          - API info (public)")
    print("  GET  /health    - Health check (public, rate limited)")
    print("  POST /predict   - Make prediction (token required)")
    print("\n[SECURITY] Token auth:   ON")
    print("[SECURITY] Rate limiting: ON (60/min on /predict)")
    print("[SECURITY] CORS:          localhost only")
    print("[SECURITY] Debug mode:    OFF")
    print("\nPress Ctrl+C to stop")
    print("=" * 60)

    app.run(
        host='127.0.0.1',   # [SECURITY] localhost only — not exposed to network
        port=5000,
        debug=False          # [SECURITY] never True in production
    )