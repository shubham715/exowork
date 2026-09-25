import React from "react";
import AuthLayout from "../../features/auth/components/AuthLayout.jsx";
import LoginForm from "../../features/auth/components/LoginForm.jsx";
import { ADMIN_LOGIN_ROLE } from "../../features/auth/authConfig.js";

export default function AdminLogin() {
  return (
    <AuthLayout admin>
      <LoginForm roles={[ADMIN_LOGIN_ROLE]} admin />
    </AuthLayout>
  );
}
