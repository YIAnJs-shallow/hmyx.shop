function validateLogin() {
    let username = document.forms["loginForm"]["username"].value;
    let password = document.forms["loginForm"]["password"].value;

    if (username.trim() === "") {
        alert("请输入用户名！");
        return false;
    }
    if (password.trim() === "") {
        alert("请输入密码！");
        return false;
    }
    if (password.length < 6) {
        alert("密码长度不能小于 6 位！");
        return false;
    }
    return true;
}