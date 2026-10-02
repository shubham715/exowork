export const PUBLIC_LOGIN_ROLES = [
  {
    id: "employer",
    label: "Employer",
    identifierLabel: "Work email",
    identifierPlaceholder: "name@company.com",
    destination: "/employer/dashboard",
  },
  {
    id: "center",
    label: "Training Center",
    identifierLabel: "Email or mobile",
    identifierPlaceholder: "Email or registered mobile",
    destination: "/center/dashboard",
  },
  {
    id: "candidate",
    label: "Candidate",
    identifierLabel: "Mobile number or email",
    identifierPlaceholder: "Registered mobile or email",
    destination: "/candidate/dashboard",
  },
];

export const ADMIN_LOGIN_ROLE = {
  id: "admin",
  label: "Admin operations",
  identifierLabel: "Admin email",
  identifierPlaceholder: "name@exowork.in",
  destination: "/admin/dashboard",
};
