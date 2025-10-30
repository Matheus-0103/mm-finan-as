// Função para alternar entre abas
function showTab(tabName) {
    const tabs = document.querySelectorAll('.tab-content');
    const buttons = document.querySelectorAll('.tab-button');
    
    tabs.forEach(tab => tab.classList.remove('active'));
    buttons.forEach(button => button.classList.remove('active'));
    
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}

// Função para mostrar seção de auth
function showAuth() {
    document.getElementById('auth-section').classList.remove('hidden');
    document.querySelectorAll('.dashboard').forEach(d => d.classList.add('hidden'));
}

// Função para mostrar dashboard baseado no tipo
function showDashboard(tipo) {
    document.getElementById('auth-section').classList.add('hidden');
    document.querySelectorAll('.dashboard').forEach(d => d.classList.add('hidden'));
    document.getElementById(`dashboard-${tipo}`).classList.remove('hidden');
}

// Validação e envio do formulário de login
document.getElementById('loginForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const email = document.getElementById('loginEmail').value;
    const senha = document.getElementById('loginSenha').value;
    
    if (!email || !senha) {
        alert('Por favor, preencha todos os campos.');
        return;
    }
    
    // Verificar se há usuário cadastrado
    const user = JSON.parse(localStorage.getItem('user'));
    if (!user) {
        alert('Nenhum usuário cadastrado. Faça o cadastro primeiro.');
        return;
    }
    
    if (user.email !== email || user.senha !== senha) {
        alert('Email ou senha incorretos.');
        return;
    }
    
    // Mostrar dashboard baseado no tipo
    showDashboard(user.tipo);
});

// Validação e envio do formulário de cadastro
document.getElementById('cadastroForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const nome = document.getElementById('nome').value;
    const sobrenome = document.getElementById('sobrenome').value;
    const dataNascimento = document.getElementById('dataNascimento').value;
    const cpf = document.getElementById('cpf').value;
    const email = document.getElementById('cadastroEmail').value;
    const senha = document.getElementById('cadastroSenha').value;
    const tipoUsuario = document.getElementById('tipoUsuario').value;
    
    if (!nome || !sobrenome || !dataNascimento || !cpf || !email || !senha || !tipoUsuario) {
        alert('Por favor, preencha todos os campos.');
        return;
    }
    
    if (cpf.length !== 11 || isNaN(cpf)) {
        alert('CPF deve ter 11 dígitos numéricos.');
        return;
    }
    
    // Salvar usuário (simulação com localStorage)
    const user = { nome, sobrenome, dataNascimento, cpf, email, senha, tipo: tipoUsuario };
    localStorage.setItem('user', JSON.stringify(user));
    
    alert('Cadastro realizado com sucesso! Agora faça o login.');
    showTab('login');
});

// Formulário de gasto (para usuário)
document.getElementById('gastoForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const descricao = document.getElementById('descricao').value;
    const valor = document.getElementById('valor').value;
    
    if (!descricao || !valor) {
        alert('Preencha descrição e valor.');
        return;
    }
    
    alert(`Gasto adicionado: ${descricao} - R$ ${valor}`);
});