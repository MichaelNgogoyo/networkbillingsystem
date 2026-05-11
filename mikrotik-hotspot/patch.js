            const payBtn = document.getElementById("payBtn");

            paymentForm.addEventListener("submit", async function(e) {
                e.preventDefault();
                const phone = document.getElementById("phone").value;
                if (!phone || !selectedPlanData) {
                    alert("Please enter phone number and select a package");
                    return;
                }
                
                payBtn.textContent = "Processing...";
                payBtn.disabled = true;

                try {
                    const formData = new FormData(this);
                    
                    const response = await fetch(this.action, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        if (result.type === 'trial' || result.type === 'existing') {
                            // Automatically log the user into the MikroTik router
                            payBtn.textContent = "Logging you in...";
                            
                            // Create a hidden form to submit to MikroTik's login URL
                            const mtForm = document.createElement('form');
                            mtForm.method = 'post';
                            mtForm.action = '$(link-login-only)';
                            
                            const userInput = document.createElement('input');
                            userInput.type = 'hidden';
                            userInput.name = 'username';
                            userInput.value = result.username || phone;
                            
                            const passInput = document.createElement('input');
                            passInput.type = 'hidden';
                            passInput.name = 'password';
                            passInput.value = result.password || phone;
                            
                            const dstInput = document.createElement('input');
                            dstInput.type = 'hidden';
                            dstInput.name = 'dst';
                            dstInput.value = '$(link-orig)';
                            
                            mtForm.appendChild(userInput);
                            mtForm.appendChild(passInput);
                            mtForm.appendChild(dstInput);
                            document.body.appendChild(mtForm);
                            mtForm.submit();
                        } else {
                            // It's a payment prompt
                            payBtn.textContent = "Check your phone!";
                            alert("M-Pesa STK Push sent! Please enter your M-Pesa PIN on your phone to complete payment. Once you receive the SMS confirmation from Safaricom, log in with your phone number.");
                            // Change UI to tell them to login
                            activateTab("login");
                        }
                    } else {
                        alert("Error: " + (result.message || "Unknown error"));
                        payBtn.textContent = "Pay & Connect";
                        payBtn.disabled = false;
                    }
                } catch (error) {
                    alert("Connection error. Please try again. Make sure you are connected to the Hotspot.");
                    payBtn.textContent = "Pay & Connect";
                    payBtn.disabled = false;
                }
            });
