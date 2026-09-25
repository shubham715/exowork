export function validateLogin({ identifier, password }) {
  const errors = {};

  if (!identifier.trim()) {
    errors.identifier = "Enter your registered email or mobile number.";
  }

  if (!password) {
    errors.password = "Enter your password.";
  } else if (password.length < 8) {
    errors.password = "Password must contain at least 8 characters.";
  }

  return errors;
}
