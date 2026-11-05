import React from 'react';
import {CircleAlert, LoaderCircle, RotateCw} from 'lucide-react';

export default function AuthSettingsStatus({mode, error, onRetry}) {
  return (
    <div className="login-form">
      <section className="auth-settings-status">
        <div className="auth-settings-status-copy" role={error ? 'alert' : 'status'}>
          <span className="auth-settings-status-icon" aria-hidden="true">
            {error ? <CircleAlert /> : <LoaderCircle className="auth-settings-spinner" />}
          </span>
          <div>
            <h3>{error ? 'Connection interrupted' : `Getting your ${mode} ready`}</h3>
            <p>{error || 'Loading your secure account options.'}</p>
          </div>
        </div>
        {error && (
          <button type="button" className="btn primary" onClick={onRetry}>
            <RotateCw aria-hidden="true" /> Retry
          </button>
        )}
      </section>
    </div>
  );
}
