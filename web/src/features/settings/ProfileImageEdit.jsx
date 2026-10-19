import React, { useRef, useState, useEffect } from "react";
import { Button } from "@/shared/ui/button";

export default function ProfileImageEdit({ onChange, onRemove, userId }) {
    const [image, setImage] = useState(null);
    const [isEditing, setIsEditing] = useState(false);
    const inputRef = useRef();

    // localStorage'dan profil fotoğrafını yükle
    useEffect(() => {
        async function fetchProfilePhoto() {
        try {
            const res = await fetch(`/api/user/user_getphoto.php?id=${userId}`);
            const result = await res.json();
            if (result.success && result.avatar) {
            setImage(result.avatar);
            }
        } catch (err) {
            console.error("Profil fotoğrafı alınamadı:", err);
        }
        }
        fetchProfilePhoto();
    }, [userId]);

    const handleImageChange = (e) => {
        if (e.target.files && e.target.files[0]) {
            const file = e.target.files[0];
            const reader = new FileReader();

            reader.onload = (event) => {
                const base64String = event.target.result;
                setImage(base64String);
                setIsEditing(true);
            };

            reader.readAsDataURL(file);
        }
    };

    const handleRemove = () => {
        setImage(null);
        setIsEditing(false);
        // localStorage'dan kaldır
        localStorage.removeItem('userProfileImage');
        // Event tetikle
        window.dispatchEvent(new Event('profileImageUpdated'));
        if (onRemove) onRemove();
        if (inputRef.current) inputRef.current.value = "";
    };

    const handleSave = () => {
        if (image) {
            // localStorage'a kaydet
            localStorage.setItem('userProfileImage', image);
            // Event tetikle (diğer component'lerin güncellenmesi için)
            window.dispatchEvent(new Event('profileImageUpdated'));
            if (onChange) onChange(image);
        }
        setIsEditing(false);
    };

    const handleClick = () => {
        if (inputRef.current) inputRef.current.click();
    };

    return (
        <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
            {/* Avatar */}
            <div className="flex h-[120px] w-[120px] shrink-0 items-center justify-center overflow-hidden rounded-full bg-luma-input">
                {image ? (
                    <img
                        src={image}
                        alt="Profil fotoğrafı"
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <svg width="36" height="44" viewBox="0 0 36 44" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M27.8647 9.77778C27.8647 4.44444 23.4203 0 17.8647 0C12.3092 0 7.86475 4.44444 7.86475 9.77778C7.86475 15.1111 12.3092 19.5556 17.8647 19.5556C23.4203 19.5556 27.8647 15.1111 27.8647 9.77778ZM12.3092 9.77778C12.3092 6.88889 14.7536 4.44444 17.8647 4.44444C20.9759 4.44444 23.4203 6.88889 23.4203 9.77778C23.4203 12.6667 20.9759 15.1111 17.8647 15.1111C14.7536 15.1111 12.3092 12.6667 12.3092 9.77778Z" fill="white" />
                        <path d="M1.4174 31.3329L0.306287 35.5551C-0.360379 37.5551 0.0840652 39.7773 1.4174 41.5551C2.75073 43.1107 4.52851 43.9996 6.75073 43.9996H28.973C30.973 43.9996 32.973 43.1107 34.0841 41.3329C35.4174 39.5551 35.8618 37.3329 35.1952 35.3329L34.0841 31.1107C32.7507 25.5551 27.8618 21.7773 22.3063 21.7773H13.4174C7.86184 21.7773 2.97295 25.5551 1.4174 31.3329ZM4.52851 36.6662L5.63962 32.444C6.75073 28.6662 9.86184 26.2218 13.4174 26.2218H22.3063C25.8618 26.2218 28.973 28.6662 30.0841 32.444L31.1952 36.6662C31.4174 37.3329 31.1952 38.2218 30.7507 38.8885C30.5285 39.1107 29.8618 39.5551 28.973 39.5551H6.75073C5.86184 39.5551 5.4174 39.1107 5.19518 38.6662C4.52851 38.2218 4.30629 37.3329 4.52851 36.6662Z" fill="white" />
                    </svg>
                )}
                <input
                    type="file"
                    accept="image/*"
                    className="hidden"
                    ref={inputRef}
                    onChange={handleImageChange}
                />
            </div>
            {/* Butonlar */}
            <div className="flex gap-2.5">
                {isEditing ? (
                    <>
                        <Button onClick={handleSave} className="h-auto min-w-[100px] border border-transparent py-3">
                            Kaydet
                        </Button>
                        <Button
                            onClick={handleRemove}
                            variant="secondary"
                            className="h-auto min-w-[100px] border border-transparent bg-white/10 py-3 hover:bg-white/15"
                        >
                            İptal
                        </Button>
                    </>
                ) : (
                    <>
                        <Button onClick={handleClick} className="h-auto min-w-[100px] border border-transparent py-3">
                            Değiştir
                        </Button>
                        {image && (
                            <Button
                                onClick={handleRemove}
                                variant="secondary"
                                className="h-auto min-w-[100px] border border-transparent bg-white/10 py-3 hover:bg-white/15"
                            >
                                Kaldır
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
