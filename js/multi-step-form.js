document.addEventListener("DOMContentLoaded", function() {
    const steps = Array.from(document.querySelectorAll(".form-step"));
    const nextButton = document.querySelectorAll(".btn-next-step");
    const prevButton = document.querySelectorAll(".btn-prev-step");
    const farmerForm = document.querySelector("#farmerRegistrationForm");
    const buyerForm = document.querySelector("#buyerRegistrationForm");
    const indicators = document.querySelectorAll(".step-indicator");

    let currentStep = 0;

    nextButton.forEach(button => {
        button.addEventListener("click", (e) => {
            e.preventDefault();
            if (!validateForm()) return;
            
            steps[currentStep].classList.remove("active");
            indicators[currentStep].classList.remove("active");
            indicators[currentStep].classList.add("completed");
            currentStep++;
            
            if (currentStep < steps.length) {
                steps[currentStep].classList.add("active");
                indicators[currentStep].classList.add("active");
            }
        });
    });

    prevButton.forEach(button => {
        button.addEventListener("click", (e) => {
            e.preventDefault();
            steps[currentStep].classList.remove("active");
            indicators[currentStep].classList.remove("active");
            indicators[currentStep].classList.remove("completed");
            currentStep--;
            steps[currentStep].classList.add("active");
            indicators[currentStep].classList.add("active");
        });
    });

    function validateForm() {
        let isValid = true;
        const currentInputs = steps[currentStep].querySelectorAll("input[required], select[required], textarea[required]");
        
        currentInputs.forEach(input => {
            if (!input.checkValidity() || !input.value.trim()) {
                input.reportValidity();
                isValid = false;
            }
        });
        
        // Special validation for buyer business type selection
        if (buyerForm && currentStep === 0) {
            const businessType = document.getElementById('business_type');
            if (businessType && !businessType.value) {
                alert('Please select a business type to continue.');
                isValid = false;
            }
        }
        
        return isValid;
    }

    // Show the first step
    if (steps.length > 0) {
        steps[currentStep].classList.add("active");
        indicators[currentStep].classList.add("active");
    }
});

