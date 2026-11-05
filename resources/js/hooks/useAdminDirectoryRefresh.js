import {useEffect, useRef} from 'react';

export default function useAdminDirectoryRefresh(refresh) {
    const latest = useRef(refresh);
    latest.current = refresh;
    useEffect(() => {
        const update = () => { if (document.visibilityState === 'visible') latest.current(); };
        const timer = window.setInterval(update, 30000);
        window.addEventListener('focus', update);
        window.addEventListener('admin-review-updated', update);
        document.addEventListener('visibilitychange', update);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', update);
            window.removeEventListener('admin-review-updated', update);
            document.removeEventListener('visibilitychange', update);
        };
    }, []);
}
