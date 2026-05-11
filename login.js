// Login functionality
document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    const errorMessage = document.getElementById('loginError');

    // Check if already logged in with a valid API session token.
    if (localStorage.getItem('apiSessionId')) {
        window.location.href = 'index.html';
    }

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;

        errorMessage.style.display = 'none';
        
        // Disable form while processing
        const submitBtn = loginForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'A verificar...';
        
        try {
            // Use admin login endpoint which only accepts funcionarios
            const response = await API.adminLogin(email, password);
            
            if (response.success && response.utilizador && response.utilizador.funcionario) {
                localStorage.setItem('adminLoggedIn', 'true');
                localStorage.setItem('adminEmail', email);
                localStorage.setItem('adminNome', response.utilizador.nome || '');
                localStorage.setItem('adminFuncionarioId', response.utilizador.funcionario.funcionario_id || '');
                if (response.session_id) {
                    localStorage.setItem('apiSessionId', response.session_id);
                    // Compatibilidade com api.js antigo / copias que ainda leem adminToken.
                    localStorage.setItem('adminToken', response.session_id);
                } else if (response.api_token) {
                    localStorage.setItem('adminToken', response.api_token);
                }
                window.location.href = 'index.html';
            } else {
                throw new Error('Acesso negado. Apenas funcionários podem aceder ao painel de administração.');
            }
        } catch (error) {
            // Handle different error types
            let errorMsg = 'Erro ao fazer login. Verifique suas credenciais.';
            
            // The apiRequest function throws errors with message property
            if (error.message) {
                errorMsg = error.message;
            }
            
            // Log error for debugging
            console.error('Login error:', error);
            console.error('Error status:', error.status);
            console.error('Error response:', error.response);
            
            errorMessage.textContent = errorMsg;
            errorMessage.style.display = 'block';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Entrar';
        }
    });
});

