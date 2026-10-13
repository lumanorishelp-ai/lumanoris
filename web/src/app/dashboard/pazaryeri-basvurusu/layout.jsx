export const metadata = {
    title: "Pazaryeri Başvurusu",
};

export default function DashboardMarketplaceApplication({ children }) {
    return (
        <>
            <div className="dashboard-inner-layout">
                {children}
            </div>
        </>
    );
}
