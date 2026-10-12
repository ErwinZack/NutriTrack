<?php
// Database & Functions
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Authentication
if (!isset($_SESSION['worker_id']) || $_SESSION['role'] !== 'health_worker') {
    header("Location: login_health_worker.php");
    exit();
}

$pageTitle = "Help & Guide";

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar_health_worker.php';
?>

<main class="dashboard">
    <h1 class="page-title">Help & Guide</h1>

    <!-- Quick Jump Links -->
    <div class="help-quick-links">
        <a href="#bmi-reference" class="quick-link-card">
            <div class="quick-link-icon blue"><i class="fa-solid fa-table"></i></div>
            <span class="quick-link-label">BMI Reference</span>
        </a>
        <a href="#how-to-guide" class="quick-link-card">
            <div class="quick-link-icon green"><i class="fa-solid fa-list-check"></i></div>
            <span class="quick-link-label">How-to Guide</span>
        </a>
        <a href="#faqs" class="quick-link-card">
            <div class="quick-link-icon amber"><i class="fa-solid fa-circle-question"></i></div>
            <span class="quick-link-label">FAQs</span>
        </a>
        <a href="#nutrition-tips" class="quick-link-card">
            <div class="quick-link-icon purple"><i class="fa-solid fa-apple-whole"></i></div>
            <span class="quick-link-label">Nutrition Tips</span>
        </a>
    </div>

    <!-- BMI Reference Chart -->
    <section class="section" id="bmi-reference">
        <div class="section-header">
            <div class="section-header-icon blue"><i class="fa-solid fa-table"></i></div>
            <div class="section-header-text">
                <h3>BMI Reference Chart</h3>
                <p>Standard BMI classification based on WHO guidelines</p>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="table-default">
                <thead class="table-head">
                    <tr>
                        <th>BMI Range</th>
                        <th>Classification</th>
                        <th>Health Risk</th>
                        <th>Recommended Action</th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <tr>
                        <td><strong>Below 18.5</strong></td>
                        <td>
                            <span class="res-bmi-badge res-bmi-badge-underweight">
                                <span class="res-bmi-badge-dot"></span>
                                Underweight
                            </span>
                        </td>
                        <td>Malnutrition, anemia, immune deficiency</td>
                        <td>Increase caloric intake, nutrition counseling</td>
                    </tr>
                    <tr>
                        <td><strong>18.5 – 24.9</strong></td>
                        <td>
                            <span class="res-bmi-badge res-bmi-badge-normal">
                                <span class="res-bmi-badge-dot"></span>
                                Normal
                            </span>
                        </td>
                        <td>Low risk</td>
                        <td>Maintain current diet and exercise habits</td>
                    </tr>
                    <tr>
                        <td><strong>25.0 – 29.9</strong></td>
                        <td>
                            <span class="res-bmi-badge res-bmi-badge-overweight">
                                <span class="res-bmi-badge-dot"></span>
                                Overweight
                            </span>
                        </td>
                        <td>Moderate risk of chronic disease</td>
                        <td>Dietary adjustment, physical activity</td>
                    </tr>
                    <tr>
                        <td><strong>30.0 and above</strong></td>
                        <td>
                            <span class="res-bmi-badge res-bmi-badge-obese">
                                <span class="res-bmi-badge-dot"></span>
                                Obese
                            </span>
                        </td>
                        <td>High risk of diabetes, hypertension, heart disease</td>
                        <td>Immediate lifestyle intervention, medical referral</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="help-bmi-note">
            <i class="fa-solid fa-circle-info"></i>
            BMI = Weight (kg) ÷ Height² (m). This is automatically calculated when you add a health record.
        </p>
    </section>

    <!-- How-to Guide -->
    <section class="section" id="how-to-guide">
        <div class="section-header">
            <div class="section-header-icon green"><i class="fa-solid fa-list-check"></i></div>
            <div class="section-header-text">
                <h3>How-to Guide</h3>
                <p>Step-by-step instructions for common tasks in NutriTrack</p>
            </div>
        </div>

        <div class="help-steps-grid">
            <div class="help-step-card">
                <i class="help-step-icon fa-solid fa-users"></i>
                <div class="help-step-number">1</div>
                <div class="help-step-title">Add a Resident</div>
                <div class="help-step-desc">
                    Go to <strong>Residents</strong> → click <strong>+ Add Resident</strong> → fill in the resident's name, gender, birth date, and address → click <strong>Save</strong>.
                </div>
            </div>
            <div class="help-step-card">
                <i class="help-step-icon fa-solid fa-notes-medical"></i>
                <div class="help-step-number">2</div>
                <div class="help-step-title">Add a Health Record</div>
                <div class="help-step-desc">
                    Go to <strong>Health Records</strong> → click <strong>+ Add Health Record</strong> → select the resident → enter weight and height → BMI is calculated automatically.
                </div>
            </div>
            <div class="help-step-card">
                <i class="help-step-icon fa-solid fa-robot"></i>
                <div class="help-step-number">3</div>
                <div class="help-step-title">Get AI Nutrition Advice</div>
                <div class="help-step-desc">
                    Go to <strong>AI Nutrition Advisor</strong> → select a resident → click <strong>Generate Advice</strong> to get personalized nutrition recommendations.
                </div>
            </div>
            <div class="help-step-card">
                <i class="help-step-icon fa-solid fa-chart-line"></i>
                <div class="help-step-number">4</div>
                <div class="help-step-title">View Analytics</div>
                <div class="help-step-desc">
                    Go to <strong>Analytics</strong> to see BMI distribution charts, monthly trends, and overall community nutrition statistics.
                </div>
            </div>
            <div class="help-step-card">
                <i class="help-step-icon fa-solid fa-file-pdf"></i>
                <div class="help-step-number">5</div>
                <div class="help-step-title">Export Reports</div>
                <div class="help-step-desc">
                    Go to <strong>Reports</strong> → apply filters if needed → click <strong>Export PDF</strong> to download a printable nutrition report.
                </div>
            </div>
            <div class="help-step-card">
                <i class="help-step-icon fa-solid fa-user-pen"></i>
                <div class="help-step-number">6</div>
                <div class="help-step-title">Update Your Profile</div>
                <div class="help-step-desc">
                    Click your name in the header → go to <strong>My Profile</strong> → click <strong>Edit Profile</strong> or click your avatar to change your profile picture.
                </div>
            </div>
        </div>
    </section>

    <!-- FAQs -->
    <section class="section" id="faqs">
        <div class="section-header">
            <div class="section-header-icon amber"><i class="fa-solid fa-circle-question"></i></div>
            <div class="section-header-text">
                <h3>Frequently Asked Questions</h3>
                <p>Common questions health workers ask about using NutriTrack</p>
            </div>
        </div>

        <div class="help-faq-list">
            <div class="help-faq-item">
                <div class="help-faq-question" onclick="toggleFaq(this)">
                    How is BMI calculated in NutriTrack?
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="help-faq-answer">
                    BMI is automatically calculated when you enter a resident's weight (kg) and height (cm) in the health record form. The formula used is: <strong>BMI = Weight (kg) ÷ (Height in meters)²</strong>. You do not need to calculate it manually.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-question" onclick="toggleFaq(this)">
                    Can I edit or delete a health record after saving it?
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="help-faq-answer">
                    Yes. Go to <strong>Health Records</strong>, find the record you want to change, then click the <strong>Edit (pencil)</strong> button to update it or the <strong>Delete (trash)</strong> button to remove it. Deleted records cannot be recovered.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-question" onclick="toggleFaq(this)">
                    What does the AI Nutrition Advisor do?
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="help-faq-answer">
                    The AI Nutrition Advisor analyzes a resident's latest BMI and health data to generate personalized dietary recommendations and nutrition tips. It uses an AI model to provide advice tailored to the resident's current nutrition status.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-question" onclick="toggleFaq(this)">
                    How do I change my password?
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="help-faq-answer">
                    Go to <strong>My Profile</strong> by clicking your name in the top right → click <strong>Change Password</strong> → enter your current password and your new password → click <strong>Change Password</strong> to save.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-question" onclick="toggleFaq(this)">
                    Why can't I log in even with the correct password?
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="help-faq-answer">
                    Your account may be temporarily locked due to multiple failed login attempts, or your account status may be set to <strong>Inactive</strong> by the administrator. Contact your system administrator to resolve this.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-question" onclick="toggleFaq(this)">
                    How do I export a nutrition report as PDF?
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="help-faq-answer">
                    Go to <strong>Reports</strong> or <strong>Health Records</strong> → use the filters to narrow down the data you need → click the <strong>Export PDF</strong> button at the top right to download the report.
                </div>
            </div>
        </div>
    </section>

    <!-- Nutrition Tips -->
    <section class="section" id="nutrition-tips">
        <div class="section-header">
            <div class="section-header-icon purple"><i class="fa-solid fa-apple-whole"></i></div>
            <div class="section-header-text">
                <h3>Nutrition Tips by BMI Status</h3>
                <p>Practical dietary guidance for each BMI category</p>
            </div>
        </div>

        <div class="help-tips-grid">
            <div class="help-tip-card underweight">
                <span class="help-tip-badge">Underweight &mdash; BMI &lt; 18.5</span>
                <div class="help-tip-title">Increase Caloric Intake</div>
                <ul class="help-tip-list">
                    <li>Eat 5&ndash;6 small meals throughout the day</li>
                    <li>Include calorie-dense foods like nuts, eggs, and dairy</li>
                    <li>Add protein-rich foods such as fish, chicken, and legumes</li>
                    <li>Drink healthy smoothies or fortified milk</li>
                    <li>Avoid skipping meals especially breakfast</li>
                </ul>
            </div>
            <div class="help-tip-card normal">
                <span class="help-tip-badge">Normal &mdash; BMI 18.5 &ndash; 24.9</span>
                <div class="help-tip-title">Maintain a Balanced Diet</div>
                <ul class="help-tip-list">
                    <li>Follow the food plate guide: half fruits &amp; vegetables</li>
                    <li>Stay hydrated with at least 8 glasses of water daily</li>
                    <li>Keep up regular physical activity (30 mins/day)</li>
                    <li>Limit sugary drinks and processed foods</li>
                    <li>Continue regular health assessments every 3 months</li>
                </ul>
            </div>
            <div class="help-tip-card overweight">
                <span class="help-tip-badge">Overweight &mdash; BMI 25 &ndash; 29.9</span>
                <div class="help-tip-title">Adjust Diet &amp; Activity</div>
                <ul class="help-tip-list">
                    <li>Reduce intake of fried, fatty, and sugary foods</li>
                    <li>Increase vegetable and fruit portions per meal</li>
                    <li>Walk or exercise at least 30&ndash;45 minutes daily</li>
                    <li>Avoid late-night eating and large portion sizes</li>
                    <li>Monitor weight weekly and track progress</li>
                </ul>
            </div>
            <div class="help-tip-card obese">
                <span class="help-tip-badge">Obese &mdash; BMI &ge; 30</span>
                <div class="help-tip-title">Immediate Lifestyle Change</div>
                <ul class="help-tip-list">
                    <li>Consult a doctor or nutritionist immediately</li>
                    <li>Strictly limit processed foods, sweets, and sodas</li>
                    <li>Start with light exercise and gradually increase intensity</li>
                    <li>Track daily food intake using a food diary</li>
                    <li>Set realistic weight loss goals with professional guidance</li>
                </ul>
            </div>
        </div>
    </section>

</main>

<script>
// FAQ Toggle
function toggleFaq(el) {
    const answer = el.nextElementSibling;
    const isOpen = answer.classList.contains('show');
    document.querySelectorAll('.help-faq-answer').forEach(a => a.classList.remove('show'));
    document.querySelectorAll('.help-faq-question').forEach(q => q.classList.remove('open'));
    if (!isOpen) {
        answer.classList.add('show');
        el.classList.add('open');
    }
}

// Smooth Scroll for Quick Links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const href = a.getAttribute('href');
        if (href === '#') return;
        const target = document.querySelector(href);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>